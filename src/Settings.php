<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;

/**
 * Every persistent value the module owns, behind one accessor.
 *
 * PrestaShop's Configuration table is the store. It is global rather than
 * per-shop by default, which matters here: see shopContext() below.
 */
final class Settings
{
    /** Prefix for every key we own, so an uninstall can find them all. */
    const PREFIX = 'NITROSEARCH_';

    /**
     * Keys holding a credential. Listed explicitly because they must be cleared
     * on disconnect and must never be rendered into an admin template.
     *
     * @var array<int, string>
     */
    private static $secrets = array('SYNC_SECRET', 'SCOPED_SEARCH_KEY', 'EVENTS_TOKEN', 'CONNECT_TOKEN');

    /**
     * Defaults for everything the module reads. A key absent from here is a typo
     * rather than a feature — get() would silently return '' forever.
     *
     * @var array<string, mixed>
     */
    private static $defaults = array(
        'API_URL' => 'https://api.nitrosearch.io',
        'CONNECT_TOKEN' => '',
        'CONNECTED' => false,
        'VERIFIED' => false,
        'CLAIMED' => false,
        'INSTALL_ID' => '',
        'SITE_URL' => '',
        'STORE_ID' => '',
        'COLLECTION' => '',
        'SYNC_KEY_ID' => '',
        'SYNC_SECRET' => '',
        'SEARCH_PUBLIC_ID' => '',
        'SCOPED_SEARCH_KEY' => '',
        'ENGINE_HOST' => '',
        'WIDGET_LOADER_URL' => '',
        'WIDGET_BUNDLE_URL' => '',
        'EVENTS_URL' => '',
        'EVENTS_TOKEN' => '',
        'PRODUCT_LIMIT' => 0,
        'PRODUCT_COUNT' => 0,
        'AT_LIMIT' => false,
        'PLAN' => '',
        'LAST_SYNC' => '',
        'LAST_ERROR' => '',
        'STATUS_CHECKED_AT' => 0,
        'RESYNC_TOKEN_DONE' => '',
        'INDEX_CMS' => true,
        'RESULTS_TAKEOVER' => true,
        // Stand the theme's own search suggestions down once ours have mounted.
        // On by default because two stacked dropdowns showing different results is
        // never what anyone wants; a setting exists because a heavily customised
        // theme may bind something we should not be touching.
        'SUPPRESS_NATIVE_SEARCH' => true,
        'SHOW_BADGE' => false,
        'SHARE_SEARCH_DATA' => true,
        'SELECTOR' => '',
        // Appearance. Preset NAMES live here and in the settings screen; only
        // resolved token values ever reach the widget (see Support\Design).
        'DESIGN_LOOK' => 'roomy',
        'DESIGN_SCHEME' => 'light',
        'DESIGN_CORNERS' => 'rounded',
        'DESIGN_ACCENT' => '',
        'DESIGN_WIDTH' => 'auto',
        'DESIGN_PER_PAGE' => 8,
        'DESIGN_FILTERS' => 'auto',
        'LAST_BATCH_MS' => 0,
        'AVG_BATCH_MS' => 0,
        'SYNC_BATCHES_TOTAL' => 0,
        'SYNC_ITEMS_TOTAL' => 0,
        // The full-walk cursor. Kept here rather than in its own table because it
        // is a handful of scalars and must survive exactly as long as the install.
        'FULLSYNC_ACTIVE' => false,
        'FULLSYNC_PHASE' => 'product',
        'FULLSYNC_CURSOR' => 0,
        'FULLSYNC_DONE' => '',
        'FULLSYNC_TOTAL' => 0,
        'FULLSYNC_STARTED' => '',
        'DRAIN_TOKEN' => '',
        'DRAIN_RAN_AT' => 0,
    );

    /**
     * @param string $key unprefixed, e.g. 'SYNC_SECRET'
     *
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $value = Configuration::get(self::PREFIX . $key);

        if ($value === false || $value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            return isset(self::$defaults[$key]) ? self::$defaults[$key] : '';
        }

        // Configuration stores everything as a string; restore the shape the
        // default declares so callers can rely on ===.
        if (isset(self::$defaults[$key]) && is_bool(self::$defaults[$key])) {
            return (bool) $value;
        }
        if (isset(self::$defaults[$key]) && is_int(self::$defaults[$key])) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values unprefixed keys
     */
    public static function update(array $values)
    {
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            Configuration::updateValue(self::PREFIX . $key, (string) $value);
        }
    }

    /**
     * A stable per-install identity, minted once.
     *
     * It is NOT the shop URL and must not be derived from it: a shop that moves
     * domain, or runs on a staging copy, must remain distinguishable. The service
     * uses it as part of the HMAC binding, so regenerating it breaks signing until
     * the shop reconnects.
     *
     * @return string
     */
    public static function installId()
    {
        $id = (string) self::get('INSTALL_ID');
        if ($id !== '') {
            return $id;
        }

        $id = bin2hex(random_bytes(16));
        self::update(array('INSTALL_ID' => $id));

        return $id;
    }

    /**
     * @return bool true once connect() has stored a usable credential pair
     */
    public static function isConnected()
    {
        return (bool) self::get('CONNECTED')
            && (string) self::get('SYNC_KEY_ID') !== ''
            && (string) self::get('SYNC_SECRET') !== '';
    }

    /**
     * @return string the API base, never with a trailing slash
     */
    public static function apiUrl()
    {
        return rtrim((string) self::get('API_URL'), '/');
    }

    /**
     * Wipe every credential and connection flag, leaving merchant preferences.
     *
     * Disconnect must not silently keep a usable secret on disk: the service can
     * revoke its side, and a stale credential here would keep producing signed
     * requests that 401 forever.
     */
    public static function disconnect()
    {
        $clear = array(
            'CONNECTED' => false,
            'VERIFIED' => false,
            'CLAIMED' => false,
            'STORE_ID' => '',
            'COLLECTION' => '',
            'SYNC_KEY_ID' => '',
            'SEARCH_PUBLIC_ID' => '',
            'ENGINE_HOST' => '',
            'WIDGET_LOADER_URL' => '',
            'WIDGET_BUNDLE_URL' => '',
            'EVENTS_URL' => '',
            'LAST_SYNC' => '',
            'LAST_ERROR' => '',
            'RESYNC_TOKEN_DONE' => '',
        );
        foreach (self::$secrets as $secret) {
            $clear[$secret] = '';
        }

        self::update($clear);
    }

    /**
     * Remove every key this module owns. Called on uninstall only.
     */
    public static function purge()
    {
        foreach (array_keys(self::$defaults) as $key) {
            Configuration::deleteByName(self::PREFIX . $key);
        }
    }

    /**
     * @return array<int, string> the object types a full walk covers, in order
     */
    public static function indexedTypes()
    {
        $types = array('product');
        if ((bool) self::get('INDEX_CMS')) {
            $types[] = 'page';
        }

        return $types;
    }
}
