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
use DbQuery;

/**
 * The local dirty queue.
 *
 * Hooks write coalesced rows here — one row per object, last write wins — doing
 * ZERO http and ZERO payload building. That is what keeps a product save, a
 * stock movement and a checkout fast, and it is what lets the shop keep
 * recording changes while NitroSearch is unreachable.
 *
 * WHY A QUEUE AT ALL, RATHER THAN SENDING ON SAVE. Two reasons, and the second
 * is the one that matters. A synchronous send puts a network round trip inside
 * the merchant's own save path, so an outage becomes their outage. And a bulk
 * action — a CSV import, a supplier price update, a category reassignment —
 * would fire one request per row; coalescing turns ten thousand writes to the
 * same hundred products into a hundred rows.
 */
final class Outbox
{
    /**
     * @return string the fully-prefixed table name
     */
    public static function table()
    {
        return _DB_PREFIX_ . 'nitrosearch_queue';
    }

    /**
     * @return string the CREATE TABLE this module installs
     */
    public static function schema()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `object_type` VARCHAR(20) NOT NULL,
            `object_id` INT UNSIGNED NOT NULL,
            `op` VARCHAR(10) NOT NULL,
            `version` BIGINT UNSIGNED NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT \'pending\',
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `object` (`object_type`, `object_id`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    /**
     * A monotonic per-write version: milliseconds since epoch.
     *
     * THIS IS THE LAST-WRITE-WINS CLOCK the service arbitrates on, so it has to
     * increase. A constant or a second-resolution timestamp means two edits inside
     * the same second are indistinguishable, and the service — which skips any
     * item whose version is not greater than the one it holds — would silently
     * drop the second one.
     *
     * @return int
     */
    private static function version()
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Record that one object changed.
     *
     * The upsert COALESCES: a second change to the same object before the first
     * has drained updates the existing row rather than adding one, and returns it
     * to `pending` so an edit landing mid-drain is not lost.
     *
     * @param string $objectType 'product' or 'page'
     * @param int    $objectId
     * @param string $op         'upsert' or 'delete'
     */
    /**
     * Create this table if the shop has never had it.
     *
     * ⚠ THE SAME HOLE THE ORDER-REPORT TABLE HAD, and it is worth stating plainly:
     * **this module has no `upgrade/` directory and no upgrade script.** PrestaShop
     * runs `install()` when a module is INSTALLED and never when it is upgraded in
     * place, so every table and every column this module has ever wanted to add
     * reaches a fresh install and no existing shop.
     *
     * The order-report table met exactly that and the failure was invisible: the write
     * is sealed inside a `try` so a shopper's checkout cannot break over analytics, so
     * a missing table meant attribution silently produced nothing, forever, with the
     * back office reporting no error at all.
     *
     * Nothing has yet needed to ALTER this table, which is the only reason the outbox
     * has not met it too — and "has not happened yet" is not a property worth relying
     * on. Called from the write path rather than the read path because the write is
     * what fails destructively.
     *
     * RUNS ONCE PER REQUEST. `CREATE TABLE IF NOT EXISTS` is cheap but it is not free,
     * and the full walk calls `enqueueMany()` once per page of 500.
     */
    private static $schemaChecked = false;

    public static function ensureSchema()
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            Db::getInstance()->execute(self::schema());
        } catch (\Exception $e) {
            // A shop whose database user cannot CREATE keeps whatever it has. Failing
            // here would turn a missing table into a broken save on the merchant's
            // product screen, which is strictly worse than a stalled sync.
        } catch (\Throwable $e) {
            // Same, on PHP 7+ error types.
        }
    }

    public static function enqueue($objectType, $objectId, $op)
    {
        $objectId = (int) $objectId;
        if ($objectId <= 0) {
            return;
        }

        self::enqueueMany($objectType, array($objectId), $op);
    }

    /**
     * The batch form — one multi-row insert instead of N round trips.
     *
     * Used by the full walk, where a page of 500 ids would otherwise be 500
     * separate INSERT statements inside one request.
     *
     * @param string          $objectType
     * @param array<int, int> $objectIds
     * @param string          $op
     */
    public static function enqueueMany($objectType, array $objectIds, $op)
    {
        $ids = array();
        foreach ($objectIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (empty($ids)) {
            return;
        }

        self::ensureSchema();

        $db = Db::getInstance();
        $type = pSQL($objectType);
        $operation = pSQL($op);
        $version = self::version();
        $now = date('Y-m-d H:i:s');

        $rows = array();
        foreach (array_keys($ids) as $id) {
            $rows[] = "('" . $type . "', " . (int) $id . ", '" . $operation . "', "
                . $version . ", 'pending', '" . pSQL($now) . "')";
        }

        // Chunked so a very large page cannot build a statement past
        // max_allowed_packet — a limit merchants on shared hosting really do hit,
        // and one whose failure mode is the whole insert silently erroring out.
        foreach (array_chunk($rows, 200) as $chunk) {
            $db->execute(
                'INSERT INTO `' . self::table() . '`
                    (`object_type`, `object_id`, `op`, `version`, `status`, `updated_at`)
                 VALUES ' . implode(', ', $chunk) . '
                 ON DUPLICATE KEY UPDATE
                    `op` = VALUES(`op`),
                    `version` = VALUES(`version`),
                    `status` = \'pending\',
                    `updated_at` = VALUES(`updated_at`)'
            );
        }
    }

    /**
     * Claim up to $limit pending rows for sending.
     *
     * Claiming marks them so a second drain running concurrently — a cron tick
     * overlapping a page-load tick — does not send the same rows twice.
     *
     * @param int $limit
     *
     * @return array<int, array<string, string>>
     */
    public static function claim($limit)
    {
        self::reclaimStale();

        $limit = max(1, (int) $limit);
        $db = Db::getInstance();

        $query = new DbQuery();
        $query->select('id, object_type, object_id, op, version')
            ->from('nitrosearch_queue')
            ->where("status = 'pending'")
            ->orderBy('id ASC')
            ->limit($limit);

        $rows = $db->executeS($query);
        if (!is_array($rows) || empty($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        $db->execute(
            'UPDATE `' . self::table() . "` SET `status` = 'claimed'
             WHERE `id` IN (" . implode(',', $ids) . ')'
        );

        return $rows;
    }

    /**
     * Compare-and-delete: remove the row only if it is still the version we sent.
     *
     * THE VERSION CHECK IS THE WHOLE POINT. If the merchant edited the product
     * while the batch was in flight, the hook coalesced a NEWER version into the
     * same row; deleting by id alone would throw that edit away and the shop would
     * serve stale data until something else happened to touch the product. A
     * version mismatch leaves the row pending, and the next drain sends it.
     *
     * @param int $id
     * @param int $version
     */
    public static function complete($id, $version)
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . '`
             WHERE `id` = ' . (int) $id . "
               AND `status` = 'claimed'
               AND `version` = " . (int) $version
        );
    }

    /**
     * Return claimed rows to pending, e.g. after a send failed.
     *
     * @param array<int, int> $ids
     */
    public static function release(array $ids)
    {
        $clean = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        if (empty($clean)) {
            return;
        }

        Db::getInstance()->execute(
            'UPDATE `' . self::table() . "` SET `status` = 'pending'
             WHERE `id` IN (" . implode(',', $clean) . ')'
        );
    }

    /**
     * Rescue rows stuck in `claimed` by a drain that died mid-flight.
     *
     * Without this a fatal — an OOM on a wide product, a host killing a long
     * request — would strand those rows forever: they are not pending, so nothing
     * would ever pick them up again, and the merchant would see a permanently
     * stalled sync with no error anywhere.
     */
    private static function reclaimStale()
    {
        Db::getInstance()->execute(
            'UPDATE `' . self::table() . "` SET `status` = 'pending'
             WHERE `status` = 'claimed'
               AND `updated_at` < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
    }

    /**
     * @return int rows still waiting to be sent
     */
    public static function pendingCount()
    {
        $count = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . self::table() . "` WHERE `status` = 'pending'"
        );

        return (int) $count;
    }

    /**
     * @return int every row, whatever its status — what the admin screen shows
     */
    public static function total()
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . self::table() . '`');
    }

    public static function truncate()
    {
        Db::getInstance()->execute('TRUNCATE TABLE `' . self::table() . '`');
    }

    public static function drop()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }
}
