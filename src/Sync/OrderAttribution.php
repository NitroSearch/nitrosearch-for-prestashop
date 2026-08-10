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

use Context;
use Db;
use DbQuery;
use NitroSearch\Api\Client;
use NitroSearch\Settings;
use Tools;

/**
 * Search → order attribution, kept inside the shop's own session.
 *
 * When the widget adds to cart it marks the shop's OWN cart request
 * (`ns_search=1`, `ns_q=<query>`). PrestaShop fires a cart hook during that same
 * request, so the product is noted against the shopper's cart. When an order is
 * validated, the items that came from a search make up the ATTRIBUTED slice: its
 * value in minor units and a HASHED order reference go to NitroSearch.
 *
 * WHAT NEVER LEAVES THE SHOP: the real order id (hashed with the install id
 * first), the customer, the address, the payment, the basket contents. The wire
 * carries a value, a currency, an opaque reference, the product ids, and the
 * search term that led to them.
 *
 * TWO STORES, DELIBERATELY, because they have different lifetimes and different
 * failure modes:
 *
 *  - The **cart marker** lives in PrestaShop's own cookie. It is small, it is the
 *    shopper's own session, it dies with it, and a lost one costs a single
 *    attribution rather than anything a merchant would notice.
 *  - The **pending report** is a table row, because it must survive: it is
 *    written during checkout and sent later, and losing it loses revenue data
 *    that cannot be reconstructed.
 *
 * REPORTING IS NEVER DONE DURING CHECKOUT. It rides the drain, exactly as the
 * status poll does — on a platform with no job queue, "later" has to mean an
 * existing heartbeat rather than a new one. A checkout must never be slowed, and
 * must certainly never fail, because our service was briefly unreachable.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * QUEUED REPORTS ARE NOW DELIVERED RATHER THAN MERELY ATTEMPTED (2026-08-10).
 *
 * The queue below has always been durable, but the send side threw work away.
 * `Client::reportOrder()` returned a bare boolean whose `true` meant BOTH
 * "accepted" and "give up on this", because it classified every 4xx as final;
 * this loop deleted the row on `true`. So a 429 from the per-shop rate limit, a
 * 409 from a shop that was not verified yet, and a 423 from a suspended account
 * each destroyed one order permanently, with nothing recorded anywhere. The 429
 * is the expensive one: it lands hardest during a flash sale, so the busiest
 * hour of the year reported the least revenue.
 *
 * Delivery is now: send → read a tri-state answer → on `retry`, leave the row
 * and come back later with a widening delay. Four properties make that safe.
 *
 *  1. THE PAYLOAD IS FROZEN WHEN THE ORDER IS VALIDATED. `occurred_at_wire`
 *     holds the exact string that goes on the wire, written once, re-sent
 *     byte-identical on every attempt. The service deduplicates on (shop, order
 *     reference, occurred_at), so at-least-once delivery of a frozen tuple lands
 *     exactly once however many attempts it takes. Deriving the timestamp at
 *     send time — which is what this class used to do, and what the fallback in
 *     {@see wireTimestamp()} still does for rows queued by an older version —
 *     makes the wire value depend on the shop's ambient timezone, so a merchant
 *     changing their locale settings between two attempts would insert a SECOND
 *     conversion row for one order and overstate their own revenue.
 *  2. IT IS BOUNDED PER REPORT. MAX_ATTEMPTS with BACKOFF gives one report six
 *     tries over roughly nine hours, after which it is abandoned and said so.
 *  3. IT IS BOUNDED IN AGE, AND THAT BOUND IS THE IMPORTANT ONE — see
 *     REPORT_TTL_DAYS. No attempt may carry an `occurred_at` the service would
 *     rewrite, because a rewritten timestamp is a different deduplication key.
 *  4. NOTHING HERE TOUCHES THE NETWORK ON THE CHECKOUT REQUEST, and both
 *     checkout-path entry points are sealed in `catch (\Throwable)`. The only
 *     outbound call in this feature is Client::reportOrder(), reachable only
 *     from flush(), which only ever runs from the drain.
 */
final class OrderAttribution
{
    /** Key in PrestaShop's cookie holding the cart-side marker. */
    const COOKIE_KEY = 'nitrosearch_attr';

    /** Attribution expires with the shopper's interest in it. */
    const WINDOW_SECONDS = 604800; // 7 days

    /** A cookie is a few KB; cap what we put in one. */
    const MAX_TRACKED = 25;

    /**
     * Reports older than this are abandoned rather than sent.
     *
     * ⚠ THIS IS A CORRECTNESS BOUND, NOT HOUSEKEEPING, AND IT WAS TOO HIGH. It
     * was 14 days. The service will not record a conversion at an `occurred_at`
     * older than about eight days — it clamps it forward instead — and a clamped
     * timestamp is a DIFFERENT deduplication key from the one we sent. So a
     * report that sat in this queue for nine days and was sent then, having
     * already been accepted on an earlier attempt whose response we never saw,
     * would be recorded a SECOND time at a different instant and would inflate
     * the shop's revenue. Fourteen days left six days of that exposure open.
     *
     * Seven keeps every attempt strictly inside the window with a day to spare,
     * and the whole retry ladder (BACKOFF, about nine hours) finishes inside the
     * first day of it, so in practice a report is abandoned by attempt count
     * long before age is reached. Age is the backstop for the case attempts
     * cannot cover — a shop whose table predates {@see ensureSchema()} and could
     * not be altered.
     *
     * Analytics that arrive that late are also worse than absent: they silently
     * move a number somebody has already read.
     */
    const REPORT_TTL_DAYS = 7;

    /**
     * How long to wait before attempt N+1, in seconds, indexed from the attempt
     * that just failed. Widening, because the failures worth retrying have
     * different shapes: a throttle clears in under a minute, a deploy in a few,
     * an unverified or suspended shop in hours.
     *
     * The first entry is two minutes rather than the thirty seconds a queue with
     * a real scheduler would use, because on this platform the send side only
     * runs when the drain runs — at most once every ninety seconds on a shop
     * with no cron — so anything shorter is a delay that cannot be honoured.
     *
     * @var array<int, int>
     */
    const BACKOFF = array(120, 600, 1800, 7200, 21600);

    /** Attempts before a report is abandoned. One more than BACKOFF has entries. */
    const MAX_ATTEMPTS = 6;

    /**
     * How many reports one flush may work through. A ceiling on the send side,
     * separate from the caller's own limit.
     */
    const FLUSH_LIMIT = 10;

    /**
     * Whether this shop's report table has the columns added on 2026-08-10.
     *
     * Request-scoped rather than stored, because a stored "yes" that is wrong —
     * a restored database dump, a table rebuilt by hand — would make every
     * INSERT fail on a column that is not there, and losing the report is the
     * failure this whole change exists to stop. Asking the database costs one
     * `SHOW COLUMNS` per PHP request that actually queues or sends a report.
     *
     * @var bool|null null until asked
     */
    private static $extended = null;

    /**
     * @return string
     */
    public static function table()
    {
        return _DB_PREFIX_ . 'nitrosearch_order_report';
    }

    /**
     * @return string
     */
    public static function schema()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `value_cents` BIGINT NOT NULL,
            `currency` VARCHAR(3) NOT NULL,
            `occurred_at` DATETIME NOT NULL,
            `occurred_at_wire` VARCHAR(40) NOT NULL DEFAULT \'\',
            `item_ids` TEXT NOT NULL,
            `q` VARCHAR(190) NOT NULL DEFAULT \'\',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `next_attempt_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `order` (`id_order`),
            KEY `due` (`next_attempt_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    /**
     * Bring an already-installed shop's table up to the schema above.
     *
     * The module has no upgrade script — `install()` runs CREATE TABLE IF NOT
     * EXISTS once and nothing has ever altered the table afterwards — so a shop
     * that installed 1.1.0 or 1.2.0 has the three new columns missing and would
     * fail every INSERT that named one. This adds them in place, once, and
     * reports whether they are actually there.
     *
     * IT FAILS SOFT ON PURPOSE. A shop whose database user cannot ALTER keeps
     * the old table and the old behaviour: reports are still retried and still
     * bounded, just by age rather than by attempt count, because there is
     * nowhere to record an attempt count. Losing the bookkeeping is acceptable;
     * refusing to send is not.
     *
     * @return bool true when attempts / next_attempt_at / occurred_at_wire exist
     */
    private static function ensureSchema()
    {
        if (self::$extended !== null) {
            return self::$extended;
        }

        $wanted = array(
            'occurred_at_wire' => 'VARCHAR(40) NOT NULL DEFAULT \'\'',
            'attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'next_attempt_at' => 'DATETIME NULL DEFAULT NULL',
        );

        try {
            $present = array();
            $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . self::table() . '`');
            if (!is_array($columns)) {
                // The table is missing entirely, or the query was refused. Either
                // way there is nothing to add to.
                self::$extended = false;

                return false;
            }
            foreach ($columns as $column) {
                if (isset($column['Field'])) {
                    $present[(string) $column['Field']] = true;
                }
            }

            $ok = true;
            foreach ($wanted as $name => $definition) {
                if (isset($present[$name])) {
                    continue;
                }
                $ok = Db::getInstance()->execute(
                    'ALTER TABLE `' . self::table() . '` ADD COLUMN `' . $name . '` ' . $definition
                ) && $ok;
            }

            self::$extended = $ok;
        } catch (\Exception $e) {
            // PrestaShop throws on a failed query in developer mode and returns
            // false outside it. Neither may reach a shopper's request.
            self::$extended = false;
        } catch (\Throwable $e) {
            self::$extended = false;
        }

        return self::$extended;
    }

    // ── Cart side ─────────────────────────────────────────────────────────────

    /**
     * Note a product as search-added, if the widget said so on this request.
     *
     * Runs inside the shop's own add-to-cart request, which PrestaShop has
     * already authorised — this reads a marker and writes a session note, and
     * changes nothing about the cart.
     *
     * SEALED. An add to cart that failed because an analytics marker could not
     * be stored would be a straight loss of a sale. Attribution is optional; the
     * shopper's cart is not.
     */
    public static function captureAdd()
    {
        try {
            self::mark();
        } catch (\Exception $e) {
            // Deliberately silent — see above.
        } catch (\Throwable $e) {
            // Deliberately silent — see above.
        }
    }

    private static function mark()
    {
        if (!Settings::get('SHARE_SEARCH_DATA', true)) {
            return;
        }
        if (!Tools::getValue('ns_search')) {
            return;
        }

        $idProduct = (int) Tools::getValue('id_product');
        if ($idProduct <= 0) {
            return;
        }

        $cookie = Context::getContext()->cookie;
        if (!$cookie) {
            return;
        }

        $q = Tools::substr((string) Tools::getValue('ns_q'), 0, 128);

        $attr = self::readCookie($cookie);
        $attr[(string) $idProduct] = array('q' => $q, 't' => time());

        if (count($attr) > self::MAX_TRACKED) {
            $attr = array_slice($attr, -self::MAX_TRACKED, null, true);
        }

        $cookie->{self::COOKIE_KEY} = json_encode($attr);
    }

    /**
     * An order was validated: work out the attributed slice and QUEUE the report.
     *
     * SEALED, for the reason the whole feature is asynchronous: a checkout must
     * never be slowed and must certainly never fail because of anything this
     * module wanted to record about it. The seal covers the queue INSERT and the
     * one-time ALTER inside it as well as the arithmetic — a shop with a locked
     * or unalterable report table must lose an attribution, not an order.
     *
     * @param array<string, mixed> $params the actionValidateOrder hook payload
     */
    public static function orderValidated($params)
    {
        try {
            self::collect($params);
        } catch (\Exception $e) {
            // Nothing this class does is worth an order for.
        } catch (\Throwable $e) {
            // Nothing this class does is worth an order for.
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function collect($params)
    {
        if (!Settings::get('SHARE_SEARCH_DATA', true) || !Settings::isConnected()) {
            return;
        }
        if (!isset($params['order']) || !is_object($params['order'])) {
            return;
        }

        $order = $params['order'];
        $cookie = Context::getContext()->cookie;
        if (!$cookie) {
            return;
        }

        $attr = self::readCookie($cookie);
        if (empty($attr)) {
            return;
        }

        $cutoff = time() - self::WINDOW_SECONDS;
        $valueCents = 0;
        $itemIds = array();
        $query = '';

        foreach ($order->getProducts() as $line) {
            $idProduct = (string) (int) $line['product_id'];
            if (!isset($attr[$idProduct]) || (int) $attr[$idProduct]['t'] < $cutoff) {
                continue;
            }

            // total_price_tax_incl is a decimal string; scaled by the currency's
            // own exponent rather than a hardcoded 100, for the same reason the
            // catalogue prices are.
            $valueCents += self::minorUnits((string) $line['total_price_tax_incl'], (string) $order->id_currency);
            $itemIds[] = $idProduct;

            if ($query === '' && $attr[$idProduct]['q'] !== '') {
                $query = (string) $attr[$idProduct]['q'];
            }

            // Consumed. A second order must never re-attribute the same add.
            unset($attr[$idProduct]);
        }

        $cookie->{self::COOKIE_KEY} = json_encode($attr);

        if (empty($itemIds)) {
            return;
        }

        self::queue($order, $valueCents, $itemIds, $query);
    }

    /**
     * @param object              $order
     * @param int                 $valueCents
     * @param array<int, string>  $itemIds
     * @param string              $query
     */
    private static function queue($order, $valueCents, array $itemIds, $query)
    {
        $currency = new \Currency((int) $order->id_currency);
        $iso = \Validate::isLoadedObject($currency) ? strtoupper((string) $currency->iso_code) : 'EUR';

        // ⚠ THE WIRE TIMESTAMP IS STAMPED EXACTLY ONCE, HERE, FROM ONE INSTANT.
        // It is half of the key the service deduplicates on, so every attempt at
        // this report must present the same bytes. `occurred_at` stays a DATETIME
        // because the age bound compares against it; `occurred_at_wire` is the
        // formatted string that actually goes out, frozen so that no later
        // attempt can re-derive it under a different timezone and be recorded as
        // a second sale.
        $now = time();
        $stampedAt = date('Y-m-d H:i:s', $now);
        $wire = date('c', $now);

        $columns = array('id_order', 'value_cents', 'currency', 'occurred_at', 'item_ids', 'q', 'created_at');
        $values = array(
            (int) $order->id,
            (int) $valueCents,
            "'" . pSQL($iso) . "'",
            "'" . pSQL($stampedAt) . "'",
            "'" . pSQL(implode(',', $itemIds)) . "'",
            "'" . pSQL($query) . "'",
            "'" . pSQL($stampedAt) . "'",
        );

        // Omitted on a table that could not be altered, so an old shop still
        // queues its orders — it just falls back to deriving the timestamp at
        // send time, exactly as every release before this one did.
        if (self::ensureSchema()) {
            $columns[] = 'occurred_at_wire';
            $values[] = "'" . pSQL($wire) . "'";
        }

        // INSERT IGNORE on a unique id_order: the validate hook can fire more than
        // once for one order on some payment flows, and a duplicate report would
        // double-count that shop's revenue.
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . self::table() . '` (`' . implode('`, `', $columns) . '`)
             VALUES (' . implode(', ', $values) . ')'
        );
    }

    // ── Send side, off the drain heartbeat ────────────────────────────────────

    /**
     * Send any queued reports. Called by the drain, never by checkout.
     *
     * @param int $limit
     *
     * @return int how many the service accepted
     */
    public static function flush($limit = 10)
    {
        if (!Settings::isConnected() || !Settings::get('SHARE_SEARCH_DATA', true)) {
            return 0;
        }

        $extended = self::ensureSchema();
        self::expireStale();

        $limit = max(1, min((int) $limit, self::FLUSH_LIMIT));

        $query = new DbQuery();
        $query->select('*')->from('nitrosearch_order_report');
        if ($extended) {
            // A report waiting out its backoff is not due yet. Without this, one
            // report the service keeps refusing would be re-sent on every single
            // heartbeat — and, because a retryable answer stops the batch, would
            // hold every order behind it hostage until it aged out.
            $query->where('(`next_attempt_at` IS NULL OR `next_attempt_at` <= \'' . pSQL(date('Y-m-d H:i:s')) . '\')');
        }
        $query->orderBy('id ASC')->limit($limit);
        $rows = Db::getInstance()->executeS($query);

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $accepted = 0;
        foreach ($rows as $row) {
            // Belt and braces on the age bound. expireStale() has just run, but it
            // is one DELETE against a clock this code does not own — a MySQL server
            // in a different timezone from PHP is ordinary — and putting an
            // `occurred_at` on the wire that the service would rewrite is exactly
            // how one order becomes two rows of revenue.
            if (self::tooOldToSend($row)) {
                self::discard($row, 'too old to report without being re-dated by the service');
                continue;
            }

            $result = Client::reportOrder(array(
                'order_id' => (int) $row['id_order'],
                'value_cents' => (int) $row['value_cents'],
                'currency' => (string) $row['currency'],
                'occurred_at' => self::wireTimestamp($row),
                'item_ids' => array_filter(explode(',', (string) $row['item_ids'])),
                'q' => (string) $row['q'],
            ));

            if (empty($result['retry'])) {
                // Accepted, or refused in a way that will be refused identically
                // forever — a malformed payload, a shop not entitled to report, a
                // service older than the endpoint. Either way this row is finished.
                $status = (int) $result['status'];
                if ($status < 200 || $status >= 300) {
                    self::log('order report refused and dropped (' . (string) $result['error'] . ')');
                } else {
                    ++$accepted;
                }

                self::remove((int) $row['id']);
                continue;
            }

            // Worth another attempt: 429 from the per-shop rate limit, 409 from a
            // shop not verified yet, 423 from a suspended account, a 5xx, or a
            // transport failure that says nothing at all about whether the order
            // was recorded. Each of these used to delete the row.
            self::deferOrAbandon($row, $result, $extended);

            // Stop the batch rather than burning through the queue against a
            // service that has just said "not now". Every retryable answer here is
            // a SHOP-level condition — throttled, unverified, suspended, down — so
            // the next row would get the same answer, and the row that stalled is
            // no longer due, so it cannot block the queue on the next heartbeat.
            break;
        }

        return $accepted;
    }

    /**
     * Record a failed attempt and decide whether there is another one.
     *
     * @param array<string, mixed>                                        $row
     * @param array{done: bool, retry: bool, status: int, error: string}  $result
     * @param bool                                                        $extended
     */
    private static function deferOrAbandon(array $row, array $result, $extended)
    {
        if (!$extended) {
            // No column to count attempts in — an install whose table predates
            // 2026-08-10 and could not be altered. The row simply stays and is
            // retried on each heartbeat, bounded by REPORT_TTL_DAYS alone. That is
            // the old behaviour for the retryable cases, which is worse than the
            // new one and far better than deleting the order.
            return;
        }

        $attempts = (int) (isset($row['attempts']) ? $row['attempts'] : 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Give up, and say so. Nine hours of a service that could not take
            // this order is not a blip, and continuing would trade one lost
            // attribution for a queue that never drains.
            self::discard(
                $row,
                'abandoned after ' . $attempts . ' attempts (' . (string) $result['error'] . ')'
            );

            return;
        }

        $index = $attempts - 1;
        $delay = isset(self::BACKOFF[$index]) ? self::BACKOFF[$index] : self::BACKOFF[count(self::BACKOFF) - 1];

        Db::getInstance()->execute(
            'UPDATE `' . self::table() . '`
             SET `attempts` = ' . $attempts . ", `next_attempt_at` = '" . pSQL(date('Y-m-d H:i:s', time() + (int) $delay)) . "'
             WHERE `id` = " . (int) $row['id']
        );
    }

    /**
     * The exact string this report puts in `occurred_at`, on every attempt.
     *
     * A row queued on or after 2026-08-10 carries it frozen, so a retry is
     * byte-identical to the attempt it repeats and the service's deduplication
     * collapses the two into one sale.
     *
     * A row queued by an EARLIER version has no frozen value, and the fallback
     * below reproduces exactly what that version put on the wire — the same
     * derivation, ambient timezone and all. That is deliberate: those rows may
     * already have been sent once, and reformatting them here would change the
     * deduplication key and record the order twice. The hazard the fallback
     * preserves is real but it is bounded — it needs the shop's timezone to
     * change between two attempts of one report — and it empties itself, because
     * no new row is ever written without a frozen value.
     *
     * A DERIVED value is sanity-bounded before it is returned, and returns '' if
     * it falls outside — which Client::reportOrder() treats as a report to refuse
     * rather than one to stamp on the way out. A `0000-00-00` left by an old
     * MySQL, an empty column, a host whose clock is years out: each of those
     * produces a timestamp the service would move forward to something it can
     * record, and a moved timestamp is a deduplication key we never sent, so a
     * retry of an already-accepted order would be counted twice. Note the check
     * is on the derived value ONLY. A frozen value is returned untouched however
     * odd it looks, because changing it is the very thing that duplicates a sale.
     *
     * @param array<string, mixed> $row
     *
     * @return string
     */
    private static function wireTimestamp(array $row)
    {
        $frozen = isset($row['occurred_at_wire']) ? (string) $row['occurred_at_wire'] : '';
        if ($frozen !== '') {
            return $frozen;
        }

        $occurredAt = isset($row['occurred_at']) ? strtotime((string) $row['occurred_at']) : false;
        if ($occurredAt === false) {
            return '';
        }

        $now = time();
        if ($occurredAt < ($now - (self::REPORT_TTL_DAYS * 86400)) || $occurredAt > ($now + 86400)) {
            return '';
        }

        return date('c', $occurredAt);
    }

    /**
     * Is this row old enough that the service would re-date it?
     *
     * Judged on `created_at` — when the report entered the queue — because that
     * is written by this module in this module's timezone, whereas the wire
     * value may carry an offset. An unparseable date is treated as NOT too old:
     * deleting revenue data on a parse failure is the worse mistake.
     *
     * @param array<string, mixed> $row
     *
     * @return bool
     */
    private static function tooOldToSend(array $row)
    {
        $queuedAt = isset($row['created_at']) ? strtotime((string) $row['created_at']) : false;
        if ($queuedAt === false) {
            return false;
        }

        return $queuedAt < (time() - (self::REPORT_TTL_DAYS * 86400));
    }

    /**
     * Abandon reports too old to be sent, before any of them go on the wire.
     *
     * Two reasons, and the first is the one that matters. A report the service
     * would re-date is a report that could be recorded a SECOND time under a
     * different deduplication key, so age is a correctness bound and not tidying
     * — see REPORT_TTL_DAYS. The second is the original one: a shop that was
     * disconnected for weeks must not reconnect and flood the service with stale
     * revenue events, because analytics that arrive that late silently move a
     * number somebody has already read.
     *
     * The cutoff is computed in PHP rather than with NOW(), so it is compared
     * against `created_at` in the same clock that wrote it. A MySQL server in a
     * different timezone from PHP is ordinary, and it used to shift this bound by
     * hours in whichever direction happened to apply.
     */
    private static function expireStale()
    {
        $cutoff = date('Y-m-d H:i:s', time() - (self::REPORT_TTL_DAYS * 86400));

        Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . "` WHERE `created_at` < '" . pSQL($cutoff) . "'"
        );

        $dropped = (int) Db::getInstance()->Affected_Rows();
        if ($dropped > 0) {
            self::log($dropped . ' order report(s) abandoned unsent after ' . self::REPORT_TTL_DAYS . ' days');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $why
     */
    private static function discard(array $row, $why)
    {
        self::log('order report for order ' . (int) $row['id_order'] . ' ' . $why);
        self::remove((int) $row['id']);
    }

    /**
     * @param int $id
     */
    private static function remove($id)
    {
        Db::getInstance()->execute('DELETE FROM `' . self::table() . '` WHERE `id` = ' . (int) $id);
    }

    /**
     * Say, where a merchant can actually see it, that a report was given up on.
     *
     * PrestaShop's own log (Advanced Parameters → Logs) rather than the module's
     * LAST_ERROR, for two reasons. An attribution fault is not a sync fault, and
     * writing it to the field the Configure screen reads would make a perfectly
     * healthy catalogue sync look broken — and either could then overwrite the
     * other's message. And "no record anywhere" is the whole complaint being
     * fixed here: a merchant whose attributed revenue looks low needs somewhere
     * that says why, rather than a figure that is silently short.
     *
     * Locale-neutral, because a stored string outlives the language that was
     * active when it was written.
     *
     * @param string $message
     */
    private static function log($message)
    {
        if (!class_exists('PrestaShopLogger')) {
            return;
        }

        // Severity 2 is a warning. Duplicates are collapsed by PrestaShop itself,
        // so a shop with a systematic failure gets one line rather than a flood.
        \PrestaShopLogger::addLog('NitroSearch: ' . $message, 2);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param object $cookie
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readCookie($cookie)
    {
        $raw = isset($cookie->{self::COOKIE_KEY}) ? (string) $cookie->{self::COOKIE_KEY} : '';
        if ($raw === '') {
            return array();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * A decimal amount as a whole number of the currency's smallest unit.
     *
     * Scaled by moving digits, never by float multiplication: `(int) (19.99 * 100)`
     * is 1998, because 19.99 is not representable in binary.
     *
     * @param string $amount
     * @param string $idCurrency
     *
     * @return int
     */
    private static function minorUnits($amount, $idCurrency)
    {
        $currency = new \Currency((int) $idCurrency);
        $iso = \Validate::isLoadedObject($currency) ? strtoupper((string) $currency->iso_code) : 'EUR';
        $exponent = \NitroSearch\AdapterKit\CurrencyExponents::for($iso);

        $amount = trim($amount);
        $negative = strncmp($amount, '-', 1) === 0;
        $amount = ltrim($amount, '+-');

        $parts = explode('.', $amount, 2);
        $whole = preg_replace('/\D/', '', $parts[0]);
        $frac = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : '';

        $frac = Tools::substr(str_pad($frac, $exponent, '0'), 0, $exponent);
        $minor = (int) ($whole . $frac);

        return $negative ? -$minor : $minor;
    }

    public static function drop()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . self::table() . '`');
    }
}
