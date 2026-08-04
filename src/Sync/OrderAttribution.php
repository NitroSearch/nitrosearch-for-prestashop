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
 */
final class OrderAttribution
{
    /** Key in PrestaShop's cookie holding the cart-side marker. */
    const COOKIE_KEY = 'nitrosearch_attr';

    /** Attribution expires with the shopper's interest in it. */
    const WINDOW_SECONDS = 604800; // 7 days

    /** A cookie is a few KB; cap what we put in one. */
    const MAX_TRACKED = 25;

    /** Reports older than this are abandoned rather than retried forever. */
    const REPORT_TTL_DAYS = 14;

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
            `item_ids` TEXT NOT NULL,
            `q` VARCHAR(190) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    // ── Cart side ─────────────────────────────────────────────────────────────

    /**
     * Note a product as search-added, if the widget said so on this request.
     *
     * Runs inside the shop's own add-to-cart request, which PrestaShop has
     * already authorised — this reads a marker and writes a session note, and
     * changes nothing about the cart.
     */
    public static function captureAdd()
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
     * @param array<string, mixed> $params the actionValidateOrder hook payload
     */
    public static function orderValidated($params)
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

        // INSERT IGNORE on a unique id_order: the validate hook can fire more than
        // once for one order on some payment flows, and a duplicate report would
        // double-count that shop's revenue.
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . self::table() . '`
                (`id_order`, `value_cents`, `currency`, `occurred_at`, `item_ids`, `q`, `created_at`)
             VALUES (' . (int) $order->id . ', ' . (int) $valueCents . ", '" . pSQL($iso) . "', '"
                . pSQL(date('Y-m-d H:i:s')) . "', '" . pSQL(implode(',', $itemIds)) . "', '"
                . pSQL($query) . "', '" . pSQL(date('Y-m-d H:i:s')) . "')"
        );
    }

    // ── Send side, off the drain heartbeat ────────────────────────────────────

    /**
     * Send any queued reports. Called by the drain, never by checkout.
     *
     * @param int $limit
     *
     * @return int how many were sent
     */
    public static function flush($limit = 10)
    {
        if (!Settings::isConnected() || !Settings::get('SHARE_SEARCH_DATA', true)) {
            return 0;
        }

        self::expireStale();

        $query = new DbQuery();
        $query->select('*')->from('nitrosearch_order_report')->orderBy('id ASC')->limit((int) $limit);
        $rows = Db::getInstance()->executeS($query);

        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $sent = 0;
        foreach ($rows as $row) {
            $ok = Client::reportOrder(array(
                'order_id' => (int) $row['id_order'],
                'value_cents' => (int) $row['value_cents'],
                'currency' => (string) $row['currency'],
                'occurred_at' => date('c', strtotime((string) $row['occurred_at'])),
                'item_ids' => array_filter(explode(',', (string) $row['item_ids'])),
                'q' => (string) $row['q'],
            ));

            if (!$ok) {
                // Stop on the first failure rather than burning through the queue
                // against a service that is plainly unreachable. The rows stay.
                break;
            }

            Db::getInstance()->execute('DELETE FROM `' . self::table() . '` WHERE `id` = ' . (int) $row['id']);
            ++$sent;
        }

        return $sent;
    }

    /**
     * Abandon reports too old to be worth sending.
     *
     * Without this, a shop that was disconnected for a month reconnects and
     * floods the service with stale revenue events — and analytics that arrive
     * weeks late are worse than absent, because they silently move a number
     * somebody has already read.
     */
    private static function expireStale()
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . '`
             WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ' . (int) self::REPORT_TTL_DAYS . ' DAY)'
        );
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
