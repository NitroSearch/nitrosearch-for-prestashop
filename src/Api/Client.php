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
        $res = self::signed('GET', '/v1/status', '');
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
        $res = self::signed('GET', '/v1/search-key', '');
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
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
        curl_close($ch);

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
