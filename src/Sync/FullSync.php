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

use Db;
use NitroSearch\Settings;

/**
 * The full catalogue walk — and the reason this module can be trusted.
 *
 * HOOKS ARE A HINT, NOT THE TRUTH. This is the design assumption the whole sync
 * is built on, and PrestaShop justifies it more than most: the CSV importer, bulk
 * actions in the back office, direct SQL from a migration or an ERP connector,
 * and third-party modules that write with `Db::getInstance()->update()` all change
 * the catalogue without firing a single hook we can see. A sync that trusts hooks
 * to be complete is a sync that is quietly wrong on a real merchant's shop.
 *
 * So the walk is the correctness mechanism and the hooks are the latency
 * optimisation, not the other way round. It runs on install, on demand, and
 * whenever the service asks for one.
 *
 * IT IS CHUNKED AND RESUMABLE because a first sync must never enumerate the whole
 * catalogue in one request. Paging with a KEYSET cursor (WHERE id > last) and
 * persisting that cursor means a run killed by max_execution_time — routine on
 * cheap shared hosting — continues from where it stopped rather than restarting.
 */
final class FullSync
{
    /** Ids enqueued per chunk. One multi-row insert. */
    const CHUNK = 500;

    /** Seconds of enumeration per invocation before yielding. */
    const BUDGET = 15;

    /**
     * Begin — or resume — a full walk.
     *
     * Returns immediately: it records the intent and enqueues the first pages, and
     * the cron/tick path carries it the rest of the way. Calling it while a run is
     * active resumes that run rather than starting a second one.
     *
     * @param array<int, string> $onlyTypes walk only these, e.g. after the merchant
     *                                      switches CMS pages on — re-walking the
     *                                      whole catalogue to add a few pages would
     *                                      put every product back through the
     *                                      merchant's own host for nothing
     *
     * @return int how many objects the run expects to queue
     */
    public static function start(array $onlyTypes = array())
    {
        if (self::isActive()) {
            self::resumeIfStalled();

            return (int) Settings::get('FULLSYNC_TOTAL', 0);
        }

        $types = empty($onlyTypes) ? Settings::indexedTypes() : $onlyTypes;
        if (empty($types)) {
            return 0;
        }

        // A targeted run starts with every OTHER type already marked done, so the
        // walk covers exactly what was asked for.
        $done = array_values(array_diff(self::canonicalTypes(), $types));

        $total = 0;
        foreach ($types as $type) {
            $total += self::countPublished($type);
        }

        Settings::update(array(
            'FULLSYNC_ACTIVE' => true,
            'FULLSYNC_PHASE' => $types[0],
            'FULLSYNC_CURSOR' => 0,
            'FULLSYNC_DONE' => implode(',', $done),
            'FULLSYNC_TOTAL' => $total,
            'FULLSYNC_STARTED' => date('Y-m-d H:i:s'),
        ));

        self::advance();

        return $total;
    }

    /**
     * Enumerate for a bounded slice of time, then yield.
     *
     * Idempotent: the outbox upsert coalesces, so re-running a chunk after a crash
     * neither duplicates nor loses work.
     */
    public static function advance()
    {
        if (!self::isActive()) {
            return;
        }

        $deadline = microtime(true) + self::BUDGET;

        while (microtime(true) < $deadline) {
            $phase = (string) Settings::get('FULLSYNC_PHASE', 'product');
            $cursor = (int) Settings::get('FULLSYNC_CURSOR', 0);

            if (!self::isTypeEnabled($phase)) {
                self::completePhase($phase);
                if (!self::isActive()) {
                    return;
                }
                continue;
            }

            $ids = self::publishedIds($phase, $cursor, self::CHUNK);

            if (!empty($ids)) {
                Outbox::enqueueMany($phase, $ids, 'upsert');
                Settings::update(array('FULLSYNC_CURSOR' => (int) max($ids)));
            }

            if (count($ids) < self::CHUNK) {
                self::completePhase($phase);
                if (!self::isActive()) {
                    return;
                }
            }
        }
    }

    /**
     * Continue a walk whose chain was lost.
     *
     * There is no job runner to retry us, so a run interrupted by a fatal would
     * otherwise sit `active` at a stale cursor forever, reporting itself in
     * progress and never progressing. The drain calls this on every heartbeat, so
     * a stalled walk resumes unattended rather than waiting for the merchant to
     * click something.
     */
    public static function resumeIfStalled()
    {
        if (self::isActive()) {
            self::advance();
        }
    }

    /**
     * Mark a type finished and move to the next, or end the run.
     *
     * @param string $phase
     */
    private static function completePhase($phase)
    {
        $done = self::donePhases();
        if (!in_array($phase, $done, true)) {
            $done[] = $phase;
        }

        $next = null;
        foreach (self::canonicalTypes() as $candidate) {
            if (!in_array($candidate, $done, true) && self::isTypeEnabled($candidate)) {
                $next = $candidate;
                break;
            }
        }

        if ($next === null) {
            Settings::update(array(
                'FULLSYNC_ACTIVE' => false,
                'FULLSYNC_DONE' => implode(',', $done),
            ));

            return;
        }

        Settings::update(array(
            'FULLSYNC_PHASE' => $next,
            'FULLSYNC_CURSOR' => 0,
            'FULLSYNC_DONE' => implode(',', $done),
        ));
    }

    /**
     * The order a walk covers the shop: PRODUCTS FIRST, THEN CMS PAGES.
     *
     * This ordering is load-bearing rather than cosmetic. Pages consume the same
     * plan allowance as products, so on a shop near its limit whichever type is
     * walked first claims the capacity. Products are what a shopper searches for,
     * so products go first and pages take what is left. The service enforces the
     * same priority independently, because it cannot trust a client to be well
     * behaved about someone else's quota.
     *
     * @return array<int, string>
     */
    private static function canonicalTypes()
    {
        return array('product', 'page');
    }

    /**
     * @return array<int, string>
     */
    private static function donePhases()
    {
        $raw = (string) Settings::get('FULLSYNC_DONE', '');
        if ($raw === '') {
            return array();
        }

        return array_values(array_filter(explode(',', $raw)));
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    private static function isTypeEnabled($type)
    {
        return in_array($type, Settings::indexedTypes(), true);
    }

    /**
     * One keyset page of ids.
     *
     * KEYSET, NOT OFFSET. `LIMIT 500 OFFSET 40000` makes the database walk and
     * discard 40,000 rows to return 500, so a large catalogue gets quadratically
     * slower as the walk proceeds — on the merchant's own database. `WHERE id > ?`
     * uses the primary key and costs the same on the last page as the first.
     *
     * @param string $type
     * @param int    $afterId
     * @param int    $limit
     *
     * @return array<int, int>
     */
    private static function publishedIds($type, $afterId, $limit)
    {
        $afterId = (int) $afterId;
        $limit = (int) $limit;

        if ($type === 'product') {
            $sql = 'SELECT p.`id_product` AS id
                    FROM `' . _DB_PREFIX_ . 'product` p
                    WHERE p.`id_product` > ' . $afterId . '
                    ORDER BY p.`id_product` ASC
                    LIMIT ' . $limit;
        } else {
            $sql = 'SELECT c.`id_cms` AS id
                    FROM `' . _DB_PREFIX_ . 'cms` c
                    WHERE c.`id_cms` > ' . $afterId . '
                    ORDER BY c.`id_cms` ASC
                    LIMIT ' . $limit;
        }

        // Deliberately NOT filtered by `active` here. An inactive product must
        // still be walked, because the serializer refuses it and the drain turns
        // that refusal into a DELETE — which is how something that was indexed and
        // has since been disabled actually leaves the index. Filtering here would
        // make the walk skip exactly the rows that need correcting.
        $rows = Db::getInstance()->executeS($sql);
        if (!is_array($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param string $type
     *
     * @return int
     */
    private static function countPublished($type)
    {
        $table = $type === 'product' ? 'product' : 'cms';

        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . $table . '`');
    }

    /**
     * @return bool
     */
    public static function isActive()
    {
        return (bool) Settings::get('FULLSYNC_ACTIVE');
    }

    /**
     * @return array<string, mixed>
     */
    public static function state()
    {
        return array(
            'active' => self::isActive(),
            'phase' => (string) Settings::get('FULLSYNC_PHASE', 'product'),
            'cursor' => (int) Settings::get('FULLSYNC_CURSOR', 0),
            'total' => (int) Settings::get('FULLSYNC_TOTAL', 0),
            'started' => (string) Settings::get('FULLSYNC_STARTED', ''),
        );
    }

    public static function cancel()
    {
        Settings::update(array('FULLSYNC_ACTIVE' => false));
    }
}
