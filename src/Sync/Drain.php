<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Sync;

if (!defined('_PS_VERSION_')) {
    exit;
}

use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Sends the outbox to NitroSearch, in batches, politely.
 *
 * THE HARD PART ON PRESTASHOP IS THAT THERE IS NO JOB QUEUE. WooCommerce bundles
 * Action Scheduler; PrestaShop has nothing equivalent, and a merchant may never
 * configure a cron job. A module that only syncs when cron is set up is a module
 * that silently never syncs for a large share of the shops that install it — and
 * "silently" is the problem, because the merchant sees an empty index and no
 * error.
 *
 * So there are two entry points and they are deliberately different:
 *
 * - {@see run()} is the CRON path. It drains until the queue is empty or its
 *   budget runs out, self-pacing between batches.
 * - {@see tick()} is the FALLBACK, fired after a front-office page has already
 *   been sent to the shopper. It does at most ONE batch, at most once every
 *   INTERVAL seconds, and only after the response is flushed — so a shop with no
 *   cron still syncs, and no shopper ever waits for it.
 *
 * Both are safe to run concurrently: the outbox claims rows before sending.
 */
final class Drain
{
    /** The service refuses more than this per request, so it is a hard ceiling. */
    const BATCH = 100;

    /** Rest for this fraction of the last batch's own duration before the next. */
    const DUTY_CYCLE = 0.5;

    /** Never sleep longer than this between batches. */
    const MAX_PAUSE_MS = 2000;

    /** Stop chaining batches once this share of memory_limit is in use. */
    const MEMORY_HEADROOM = 0.75;

    /** Minimum seconds between two page-load fallback ticks. */
    const TICK_INTERVAL = 90;

    /** How long one cron invocation may spend, in seconds. */
    const CRON_BUDGET = 50;

    /** @var int wall-clock ms the most recent batch took — the self-throttle input */
    private static $lastElapsedMs = 0;

    /**
     * The cron entry point: drain until empty, out of budget, or a send fails.
     *
     * @return array{batches: int, items: int, stopped: string}
     */
    public static function run()
    {
        if (!Settings::isConnected()) {
            return array('batches' => 0, 'items' => 0, 'stopped' => 'not_connected');
        }

        Settings::update(array('DRAIN_RAN_AT' => time()));

        // Housekeeping that rides the same heartbeat rather than needing its own
        // schedule: pick up a resync request, and keep a stalled full walk moving.
        ResyncCheck::maybeRun();
        FullSync::resumeIfStalled();

        // Queued order reports ride this heartbeat rather than a schedule of their
        // own. On a platform with no job queue, "send it later" has to mean an
        // existing tick — and checkout must never wait on our service.
        OrderAttribution::flush();

        $deadline = microtime(true) + self::CRON_BUDGET;
        $batches = 0;
        $items = 0;

        while (microtime(true) < $deadline) {
            $result = self::drainOnce();
            $items += $result['items'];

            if ($result['status'] === 'empty') {
                return array('batches' => $batches, 'items' => $items, 'stopped' => 'empty');
            }

            ++$batches;

            if ($result['status'] === 'error') {
                return array('batches' => $batches, 'items' => $items, 'stopped' => 'error');
            }
            if ($result['status'] === 'partial') {
                return array('batches' => $batches, 'items' => $items, 'stopped' => 'drained');
            }
            if (!self::hasMemoryHeadroom()) {
                // A batch of wide products with many combinations can hydrate a lot
                // of objects. Near the limit, stop rather than risk an OOM that
                // kills the drain mid-flight and strands the claimed rows.
                return array('batches' => $batches, 'items' => $items, 'stopped' => 'memory');
            }

            self::throttle();
        }

        return array('batches' => $batches, 'items' => $items, 'stopped' => 'budget');
    }

    /**
     * The no-cron fallback: at most one batch, after the shopper's page is gone.
     *
     * Called from a front-office hook. It returns immediately unless the interval
     * has elapsed and there is work, then defers the actual send to shutdown so
     * nothing is added to the page's time to first byte.
     */
    public static function tick()
    {
        if (!Settings::isConnected()) {
            return;
        }

        $lastRan = (int) Settings::get('DRAIN_RAN_AT', 0);
        if (time() - $lastRan < self::TICK_INTERVAL) {
            return;
        }

        // Claim the interval BEFORE doing the work, so several concurrent page
        // loads do not all decide it is their turn.
        Settings::update(array('DRAIN_RAN_AT' => time()));

        if (Outbox::pendingCount() <= 0) {
            return;
        }

        register_shutdown_function(array(__CLASS__, 'runDeferredTick'));
    }

    /**
     * One batch, after the response has been handed to the shopper.
     *
     * `fastcgi_finish_request` closes the connection first where it exists, so the
     * browser is not held open by our sync at all. Where it does not — mod_php —
     * the work still happens after the page content is complete, which is the best
     * available and still invisible to the shopper.
     */
    public static function runDeferredTick()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        try {
            ResyncCheck::maybeRun();
            OrderAttribution::flush(3);
            self::drainOnce();
        } catch (\Exception $e) {
            self::recordError('tick: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // A fatal here would land in a shopper's request. It cannot be allowed
            // to surface, and there is nothing useful to do but record it.
            self::recordError('tick: ' . $e->getMessage());
        }
    }

    /**
     * Send one batch.
     *
     * @return array{status: string, items: int} status is empty|partial|full|error
     */
    private static function drainOnce()
    {
        $rows = Outbox::claim(self::BATCH);
        if (empty($rows)) {
            return array('status' => 'empty', 'items' => 0);
        }

        $items = array();
        $ids = array();

        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $type = (string) $row['object_type'];
            $objectId = (int) $row['object_id'];
            $op = (string) $row['op'];

            $item = null;

            if ($op === 'upsert') {
                // The serializers return a COMPLETE wire item — `{op, data}` — not
                // a bare data blob, because the kit's builder owns that shape. Do
                // not wrap this again: `{op, version, data: {op, data}}` is a
                // nested item the service rejects wholesale with a 422 that names
                // no field.
                $item = $type === 'product'
                    ? ProductSerializer::serialize($objectId)
                    : CmsSerializer::serialize($objectId);
            }

            if ($item === null) {
                // Either a delete was queued, or the serializer refused the object:
                // gone, disabled, or no longer visible to search. Both become a
                // DELETE, so anything that stopped being public leaves the index
                // instead of lingering in it.
                $item = array('op' => 'delete', 'data' => self::tombstone($type, $objectId));
            }

            // THE VERSION IS THE OUTBOX ROW'S, not the serializer's. It is stamped
            // when the change was recorded, so it orders the CHANGE rather than the
            // moment we happened to send it — and it is the same value the
            // compare-and-delete checks, so the row we clear is provably the row we
            // sent.
            $item['version'] = (int) $row['version'];

            $items[] = $item;
        }

        $startedAt = microtime(true);
        $result = Client::ingestBatch($items);
        self::$lastElapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$result['ok']) {
            // Leave them pending for the next attempt. Nothing is lost: the rows
            // are still there, and the coalescing upsert means a change that lands
            // meanwhile simply updates one of them.
            Outbox::release($ids);
            self::recordError('HTTP ' . $result['status'] . ': ' . substr((string) $result['error'], 0, 200));

            return array('status' => 'error', 'items' => 0);
        }

        foreach ($rows as $row) {
            Outbox::complete((int) $row['id'], (int) $row['version']);
        }

        self::recordSuccess(count($items));

        return array(
            'status' => count($rows) >= self::BATCH ? 'full' : 'partial',
            'items' => count($items),
        );
    }

    /**
     * A delete payload.
     *
     * STATING THE TYPE MATTERS HERE MORE THAN ON MOST PLATFORMS. PrestaShop's
     * id_product and id_cms are separate sequences, so product 12 and CMS page 12
     * both exist in an ordinary shop. A bare id would not say which one to remove.
     *
     * @param string $type
     * @param int    $objectId
     *
     * @return array<string, mixed>
     */
    private static function tombstone($type, $objectId)
    {
        return array('id' => (int) $objectId, 'object_type' => $type);
    }

    /**
     * Rest for a slice of the last batch's own duration, capped.
     *
     * ADAPTIVE AND CONFIG-FREE, which is the point: a fast host barely pauses, a
     * slow or loaded one rests more. It governs load on the MERCHANT's server,
     * where the service's own rate limit cannot help — a host slower than the
     * service's ceiling never triggers a 429 and would simply be pegged.
     */
    private static function throttle()
    {
        $pauseMs = (int) min(self::MAX_PAUSE_MS, round(self::$lastElapsedMs * self::DUTY_CYCLE));
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    /**
     * @return bool true while this process has room below its memory_limit
     */
    private static function hasMemoryHeadroom()
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return true;
        }

        return memory_get_usage(true) < ($limit * self::MEMORY_HEADROOM);
    }

    /**
     * @return int bytes, or -1 for unlimited/unparseable
     */
    private static function memoryLimitBytes()
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
        }
    }

    /**
     * @param string $message kept locale-neutral so it cannot go stale in whichever
     *                        language happened to be active when the sync ran
     */
    private static function recordError($message)
    {
        Settings::update(array('LAST_ERROR' => $message));
    }

    /**
     * @param int $items
     */
    private static function recordSuccess($items)
    {
        $previous = (int) Settings::get('AVG_BATCH_MS', 0);
        $elapsed = self::$lastElapsedMs;
        // An exponential moving average weighting the newest batch ~30%: it tracks
        // recent performance without storing a history.
        $average = $previous > 0 ? (int) round(($previous * 0.7) + ($elapsed * 0.3)) : $elapsed;

        Settings::update(array(
            'LAST_SYNC' => date('Y-m-d H:i:s'),
            'LAST_ERROR' => '',
            'LAST_BATCH_MS' => $elapsed,
            'AVG_BATCH_MS' => $average,
            'SYNC_BATCHES_TOTAL' => (int) Settings::get('SYNC_BATCHES_TOTAL', 0) + 1,
            'SYNC_ITEMS_TOTAL' => (int) Settings::get('SYNC_ITEMS_TOTAL', 0) + $items,
        ));
    }
}
