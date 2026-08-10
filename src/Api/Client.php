<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

use NitroSearch\Settings;
use NitroSearch\Support\Hmac;

/**
 * HTTP client for the NitroSearch service.
 *
 * `connect` is unauthenticated — it is how a shop first announces itself and
 * receives its credentials. Everything else is HMAC-signed with the sync secret.
 *
 * Every method returns an array and never throws. A sync fault must never surface
 * as a 500 on a merchant's shop or a fatal inside a cron tick.
 */
final class Client
{
    /**
     * Register this shop and persist the returned credentials.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function connect()
    {
        $siteUrl = self::shopUrl();
        $installId = Settings::installId();

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
        );
        $token = (string) Settings::get('CONNECT_TOKEN');
        if ($token !== '') {
            $headers[] = 'X-NS-Connect-Token: ' . $token;
        }

        $body = json_encode(array(
            'site_url' => $siteUrl,
            'install_id' => $installId,
            // Declaring the platform is what makes the service hand back the
            // PrestaShop widget bundle rather than WooCommerce's, and what makes
            // it namespace our document ids by type. It is not cosmetic.
            'platform' => 'prestashop',
        ));

        $res = self::request('POST', Settings::apiUrl() . '/v1/connect', $headers, $body, 20);

        if (!$res['ok'] && $res['status'] === 0) {
            return array('ok' => false, 'error' => $res['error']);
        }

        $decoded = json_decode($res['body'], true);
        if ($res['status'] !== 201 || !is_array($decoded)) {
            return array('ok' => false, 'error' => 'HTTP ' . $res['status'] . ': ' . $res['body']);
        }

        Settings::update(array(
            'CONNECTED' => true,
            'SITE_URL' => $siteUrl,
            'STORE_ID' => self::pluck($decoded, array('store_id')),
            'SYNC_KEY_ID' => self::pluck($decoded, array('sync', 'key_id')),
            'SYNC_SECRET' => self::pluck($decoded, array('sync', 'secret')),
        ));

        // The search block only arrives once the shop is verified; on a fresh
        // connect it is absent and that is the normal path, not a failure.
        if (isset($decoded['search']) && is_array($decoded['search'])) {
            self::storeSearch($decoded['search']);
        }
        if (isset($decoded['widget']) && is_array($decoded['widget'])) {
            self::storeWidget($decoded['widget']);
        }
        if (isset($decoded['events']) && is_array($decoded['events'])) {
            self::storeEvents($decoded['events']);
        }

        return array('ok' => true);
    }

    /**
     * Ask the service to prove control of this shop's hostname.
     *
     * It answers by fetching this module's public verify controller over a
     * server-to-server request; we never prove anything from inside this call.
     * When the shop cannot be reached from the outside — a firewall, a staging
     * host, localhost — verification simply stays pending, which is correct rather
     * than an error to surface loudly.
     *
     * @return array{ok: bool, verified: bool, reason: string}
     */
    public static function verify()
    {
        $res = self::signed('POST', '/v1/verify', '');
        if (!$res['ok']) {
            return array('ok' => false, 'verified' => false, 'reason' => 'unreachable');
        }

        $body = is_array($res['json']) ? $res['json'] : array();
        $verified = !empty($body['verification']['verified']);

        if ($verified) {
            Settings::update(array('VERIFIED' => true));
            if (isset($body['search']) && is_array($body['search'])) {
                self::storeSearch($body['search']);
            }
            if (isset($body['widget']) && is_array($body['widget'])) {
                self::storeWidget($body['widget']);
            }
        }

        $reason = isset($body['verification']['reason']) ? (string) $body['verification']['reason'] : '';

        return array('ok' => true, 'verified' => $verified, 'reason' => $reason);
    }

    /**
     * Send one signed batch of catalogue changes.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array{ok: bool, status: int, json: mixed, error: string}
     */
    public static function ingestBatch(array $items)
    {
        $body = json_encode(array('items' => array_values($items)));

        return self::signed('POST', '/v1/ingest/batch', $body);
    }

    /**
     * Poll plan / limit / verified / indexed count, and pick up a resync request.
     *
     * @return array<string, mixed>
     */
    public static function status()
    {
        // A SHORT BUDGET, because the unattended heartbeat calls this and that rides
        // a shopper's page load on shops with no cron. Housekeeping does not get to
        // hold a front-office request open for twenty seconds; the next tick is five
        // minutes away and losing one costs nothing.
        $res = self::signed('GET', '/v1/status', '', 8);
        $body = is_array($res['json']) ? $res['json'] : array();

        $status = array(
            'ok' => $res['ok'],
            'verified' => !empty($body['verified']),
            'claimed' => !empty($body['claimed']),
            'plan' => isset($body['plan']) ? (string) $body['plan'] : '',
            'product_limit' => isset($body['product_limit']) ? (int) $body['product_limit'] : 0,
            'product_count' => isset($body['product_count']) ? (int) $body['product_count'] : 0,
            'at_limit' => !empty($body['at_limit']),
            // Present ONLY while the service is asking this shop to re-send its
            // whole catalogue. Its ABSENCE is the signal, so there is nothing to
            // compare against when it is missing.
            'resync' => isset($body['resync']) && is_array($body['resync']) ? $body['resync'] : null,
        );

        // Persist only when the body really looks like a status response. A 200
        // carrying something else — a proxy notice, a WAF interstitial, a host's
        // injected footer — must never flatten real stored state to defaults.
        if ($res['ok'] && array_key_exists('verified', $body)) {
            Settings::update(array(
                'VERIFIED' => $status['verified'],
                'CLAIMED' => $status['claimed'],
                'PLAN' => $status['plan'],
                'PRODUCT_LIMIT' => $status['product_limit'],
                'PRODUCT_COUNT' => $status['product_count'],
                'AT_LIMIT' => $status['at_limit'],
            ));

            if (isset($body['events']) && is_array($body['events'])) {
                self::storeEvents($body['events']);
            }
        }

        return $status;
    }

    /**
     * Fetch and persist the scoped search key, available once verified.
     *
     * This is how the widget gets its key when verification happened out of band —
     * the service's loopback, not a call we made.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function fetchSearchKey()
    {
        // Bounded for the same reason as `status()`: the daily refresh rides a
        // shopper's page load on shops with no cron.
        $res = self::signed('GET', '/v1/search-key', '', 8);
        if (!$res['ok']) {
            return array('ok' => false, 'error' => 'HTTP ' . $res['status']);
        }

        $body = $res['json'];
        if (!is_array($body) || !isset($body['scoped_search_key']) || $body['scoped_search_key'] === '') {
            // A 200 whose body did not decode to the expected shape must never
            // touch stored state: blanking a working key kills storefront search
            // until the next refresh. A stale-but-valid key beats no key.
            return array('ok' => false, 'error' => 'malformed response body');
        }

        self::storeSearch($body);

        return array('ok' => true);
    }

    /**
     * HTTP statuses that mean "come back and ask again", as opposed to "this is
     * the answer".
     *
     * Listed rather than expressed as a range because the range is the mistake
     * this constant exists to correct — see {@see reportOrder()}. Everything from
     * 500 up is retryable too, and so is a transport failure, which has no status
     * at all; neither is a single code, so both live in
     * {@see isOrderRetryable()} instead of here.
     *
     * @var array<int, int>
     */
    const ORDER_RETRY_CODES = array(401, 408, 409, 423, 425, 429);

    /**
     * Report one search-attributed order.
     *
     * THE REAL ORDER ID NEVER LEAVES THE SHOP. It is hashed with the install id
     * first, so the service receives an opaque reference that is stable for this
     * shop — enough to deduplicate and to link a repeat report to the same order,
     * and useless for identifying anything outside it. Nothing about the customer,
     * the address, the payment or the rest of the basket is included.
     *
     * ────────────────────────────────────────────────────────────────────────
     * THREE ANSWERS USED TO DESTROY AN ORDER OUTRIGHT (fixed 2026-08-10).
     *
     * Until this change the last thing this method did was treat EVERY 4xx as
     * final — "the payload is wrong, or this shop is not entitled" — and report
     * it to the caller as handled, which deleted the queued row. That reasoning
     * holds for 400 and 422 and does not hold for three answers the service
     * actually gives:
     *
     *   429 — the orders endpoint accepts a bounded number of reports per minute
     *         per shop. A shop bursting past that line lost EVERY order over it,
     *         so the busiest hour of the year reported the least revenue — and
     *         that is the hour a merchant reads when deciding whether search is
     *         worth paying for. This is the one that costs real money.
     *   409 — the shop is not verified YET. Ordinary state during onboarding,
     *         usually over within minutes, and every order placed inside that
     *         window was thrown away.
     *   423 — the account is suspended. Also a state shops come back from, e.g.
     *         after a card is replaced.
     *
     * Each cost one order permanently, with nothing recorded anywhere: the row
     * was deleted, the figure was simply lower, and "the number is low" was
     * indistinguishable from "nobody searched".
     *
     * SO THE ANSWER IS NOW A TRI-STATE the caller acts on
     * ({@see \NitroSearch\Sync\OrderAttribution::flush()}):
     *
     *   done  → any 2xx, including a 2xx that says the report was not counted;
     *           and every 4xx not listed in ORDER_RETRY_CODES — 400/422 (a
     *           payload that is wrong now will be just as wrong in an hour),
     *           403 (this shop may not report), 404 (a service older than the
     *           endpoint), 410. Retrying these spends the shop's own heartbeat
     *           to be told the same thing again.
     *   retry → 401, 408, 409, 423, 425, 429, any 5xx, and a transport failure,
     *           which arrives here as status 0 and means nothing whatsoever is
     *           known about whether the order was recorded.
     *
     * 401 IS RETRYABLE HERE AND WOULD NOT BE ON A CALLER THAT REUSED HEADERS.
     * Hmac::headers() is built fresh inside signed() on every attempt, nonce
     * included, so the next attempt is a genuinely different signed request
     * rather than a replay of the one that was just refused.
     *
     * IT NEVER DERIVES `occurred_at`. The timestamp is frozen when the order is
     * validated and re-sent byte-identical on every attempt; the service
     * deduplicates on (shop, order reference, occurred_at), so a timestamp
     * regenerated at send time would turn each retry into a SECOND conversion
     * row for one order and OVERSTATE the shop's revenue — the opposite failure
     * to the one being fixed, and the worse of the two. A report that reaches
     * here without one is refused outright rather than stamped on the way out.
     *
     * @param array<string, mixed> $report
     *
     * @return array{done: bool, retry: bool, status: int, error: string}
     */
    public static function reportOrder(array $report)
    {
        // Neither of these is a fault and neither is worth coming back for: an
        // unconnected shop has no channel to send on, and a merchant who turned
        // sharing off has already answered. The reply would be identical on
        // every future attempt, so this is done, not retry — returning "retry"
        // here (which is what the old `false` meant) would pin a row in the
        // queue until it aged out.
        if (!Settings::isConnected() || !Settings::get('SHARE_SEARCH_DATA', true)) {
            return self::orderOutcome(true, 0, 'not reporting');
        }

        $occurredAt = isset($report['occurred_at']) ? (string) $report['occurred_at'] : '';
        if ($occurredAt === '') {
            return self::orderOutcome(true, 0, 'missing occurred_at');
        }

        $itemIds = array();
        foreach ((array) (isset($report['item_ids']) ? $report['item_ids'] : array()) as $id) {
            $itemIds[] = (string) $id;
        }

        $body = json_encode(array(
            'order_ref' => hash('sha256', Settings::installId() . '|order|' . (int) (isset($report['order_id']) ? $report['order_id'] : 0)),
            'value_cents' => (int) (isset($report['value_cents']) ? $report['value_cents'] : 0),
            'currency' => (string) (isset($report['currency']) ? $report['currency'] : ''),
            'occurred_at' => $occurredAt,
            'item_ids' => array_values($itemIds),
            'q' => (string) (isset($report['q']) ? $report['q'] : ''),
        ));

        if (!is_string($body)) {
            // Unencodable payload — malformed UTF-8 in the search term, say. It
            // will not encode on the next attempt either.
            return self::orderOutcome(true, 0, 'unencodable payload');
        }

        $res = self::signed('POST', '/v1/orders', $body, 10);
        $status = (int) $res['status'];

        if ($res['ok']) {
            return self::orderOutcome(true, $status, '');
        }

        $retry = self::isOrderRetryable($status);
        $detail = $status === 0
            ? (string) $res['error']
            : 'HTTP ' . $status . ': ' . substr((string) $res['error'], 0, 200);

        return self::orderOutcome(!$retry, $status, $detail);
    }

    /**
     * Is this outcome worth another attempt?
     *
     * Status 0 is a transport failure — a timeout, a DNS blip, a refused
     * connection, a TLS error. The request never got an answer, so nothing is
     * known about whether the order was recorded, and retrying is safe precisely
     * because the payload is re-sent unchanged and the service deduplicates on
     * its contents.
     *
     * @param int $status
     *
     * @return bool
     */
    private static function isOrderRetryable($status)
    {
        $status = (int) $status;

        return $status === 0
            || $status >= 500
            || in_array($status, self::ORDER_RETRY_CODES, true);
    }

    /**
     * Build the tri-state reportOrder() answer. `done` and `retry` are always
     * exact opposites; both are named on the wire so the caller reads what it
     * means rather than negating a flag — the old boolean was returned as `true`
     * for "drop this" and `false` for "keep it", which is the reading that made
     * the defect above survive review.
     *
     * @param bool   $done
     * @param int    $status
     * @param string $error
     *
     * @return array{done: bool, retry: bool, status: int, error: string}
     */
    private static function orderOutcome($done, $status, $error)
    {
        return array(
            'done' => (bool) $done,
            'retry' => !$done,
            'status' => (int) $status,
            'error' => (string) $error,
        );
    }

    /**
     * Confirm that a requested full re-sync has STARTED.
     *
     * The token rides the signed BODY rather than the query string, because the
     * signature covers sha256(body) and not the query — putting it in the URL
     * would leave it outside the signature.
     *
     * Best effort: an unsent confirmation simply leaves the request outstanding
     * for the next check to retry.
     *
     * @param string $token
     *
     * @return bool
     */
    public static function acknowledgeResync($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return false;
        }

        $res = self::signed('POST', '/v1/resync/ack', json_encode(array('token' => $token)), 15);

        return $res['ok'];
    }

    /**
     * Sign and send. Returns a uniform shape; never throws.
     *
     * @param string $method
     * @param string $path    must be the PATH ONLY — it is a signing input
     * @param string $body
     * @param int    $timeout
     *
     * @return array{ok: bool, status: int, json: mixed, body: string, error: string}
     */
    private static function signed($method, $path, $body, $timeout = 20)
    {
        $headers = Hmac::headers(
            (string) Settings::get('SYNC_KEY_ID'),
            (string) Settings::get('SYNC_SECRET'),
            $method,
            $path,
            $body,
            (string) Settings::get('SITE_URL', self::shopUrl()),
            Settings::installId()
        );

        $lines = array('Accept: application/json');
        if ($body !== '') {
            $lines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $res = self::request($method, Settings::apiUrl() . $path, $lines, $body, $timeout);
        $res['json'] = json_decode($res['body'], true);

        // A transport error has a curl message; an HTTP error has a RESPONSE BODY
        // and no curl message at all, so `error` would be empty and the merchant
        // would be shown a bare "HTTP 422:" naming nothing. The service explains
        // its refusals in the body — surface it, bounded, rather than discarding
        // the only thing that says what is wrong.
        if (!$res['ok'] && $res['error'] === '' && $res['body'] !== '') {
            $res['error'] = substr($res['body'], 0, 500);
        }

        return $res;
    }

    /**
     * One HTTP request via cURL.
     *
     * cURL rather than PrestaShop's Tools::file_get_contents because we need the
     * status code, a bounded timeout, and control over the method — and because a
     * shop with allow_url_fopen off would otherwise be unable to sync at all.
     *
     * @param array<int, string> $headers
     *
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function request($method, $url, array $headers, $body, $timeout)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => 'PHP cURL extension is not available');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        // ⚠ THE CONNECT TIMEOUT IS BOUNDED BY THE OVERALL ONE. Some of these calls
        // ride a shopper's page load on shops with no cron, and a flat 10s connect
        // budget meant a call given a short overall timeout could still hold a
        // request open for ten seconds — on hosts without `fastcgi_finish_request`
        // (mod_php) the response is not flushed at that point, so the shopper waits.
        // A blackholed egress rule is enough to do it.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, max(2, (int) $timeout)));
        // Redirects are NOT followed. A signed request's signature covers its
        // path; following a redirect would replay those headers at a different
        // path, where they are invalid — and would send the shop's credentials
        // wherever the redirect points.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($body !== '' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        // ⚠ NO `curl_close($ch)`. It has done nothing since PHP 8.0 — the handle is
        // an object freed when `$ch` goes out of scope at the end of this method —
        // and PHP 8.5 DEPRECATED it, so calling it printed a notice on every single
        // request the module made. A notice reaching the front office lands in the
        // shop's own markup. On PHP 7 the handle is freed on scope exit just the
        // same, so removing the call is safe on every version PrestaShop runs on.

        if ($responseBody === false) {
            return array('ok' => false, 'status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'request failed');
        }

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => (string) $responseBody,
            'error' => $error,
        );
    }

    /**
     * Persist a search block, defensively.
     *
     * The key gates the whole persist, and every other field falls back to its
     * stored value when absent — the widget has NO fallback for an empty engine
     * host, so blanking it would break storefront search even with a valid key.
     *
     * @param array<string, mixed> $search
     */
    private static function storeSearch(array $search)
    {
        $key = isset($search['scoped_search_key']) ? (string) $search['scoped_search_key'] : '';
        if ($key === '') {
            return;
        }

        $update = array('SCOPED_SEARCH_KEY' => $key);

        $collection = self::pluck($search, array('collection'));
        if ($collection !== '') {
            $update['COLLECTION'] = $collection;
        }
        $host = self::pluck($search, array('engine', 'host'));
        if ($host !== '') {
            $update['ENGINE_HOST'] = $host;
        }
        $publicId = self::pluck($search, array('public_key_id'));
        if ($publicId !== '') {
            $update['SEARCH_PUBLIC_ID'] = $publicId;
        }

        Settings::update($update);

        if (isset($search['widget']) && is_array($search['widget'])) {
            self::storeWidget($search['widget']);
        }
        if (isset($search['events']) && is_array($search['events'])) {
            self::storeEvents($search['events']);
        }
    }

    /**
     * @param array<string, mixed> $widget
     */
    private static function storeWidget(array $widget)
    {
        $update = array();
        $loader = self::pluck($widget, array('loader_url'));
        if ($loader !== '') {
            $update['WIDGET_LOADER_URL'] = $loader;
        }
        $bundle = self::pluck($widget, array('bundle_url'));
        if ($bundle !== '') {
            $update['WIDGET_BUNDLE_URL'] = $bundle;
        }

        if (!empty($update)) {
            Settings::update($update);
        }
    }

    /**
     * @param array<string, mixed> $events
     */
    private static function storeEvents(array $events)
    {
        $token = self::pluck($events, array('token'));
        if ($token === '') {
            return;
        }

        Settings::update(array(
            'EVENTS_URL' => self::pluck($events, array('url')),
            'EVENTS_TOKEN' => $token,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $path
     *
     * @return string
     */
    private static function pluck(array $data, array $path)
    {
        $cursor = $data;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !isset($cursor[$segment])) {
                return '';
            }
            $cursor = $cursor[$segment];
        }

        return is_scalar($cursor) ? (string) $cursor : '';
    }

    /**
     * This shop's canonical base URL, as the service will see it.
     *
     * @return string
     */
    public static function shopUrl()
    {
        $shop = \Context::getContext()->shop;
        $scheme = \Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://';

        return rtrim($scheme . $shop->domain . $shop->getBaseURI(), '/');
    }
}
