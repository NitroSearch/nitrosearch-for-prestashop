<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Context;
use Module;
use NitroSearch\Api\Client;
use NitroSearch\Settings;
use NitroSearch\Sync\Drain;
use NitroSearch\Sync\FullSync;
use NitroSearch\Sync\Outbox;
use Tools;

/**
 * The module's Configure screen: the only surface a merchant ever uses.
 *
 * It has to answer three questions without the merchant knowing anything about
 * how the sync works — is my shop connected, is my catalogue actually indexed,
 * and if not, what do I do about it — so the status panel leads and the settings
 * come second.
 *
 * WHAT IT DELIBERATELY NEVER RENDERS is the sync secret and the scoped search
 * key. Both are credentials, an admin page is shoulder-surfed and screenshotted
 * into support tickets, and neither is any use to a merchant. Presence is shown;
 * the value is not. The cron token IS rendered, because it is the one secret the
 * merchant genuinely has to copy somewhere.
 */
final class ConfigurePage
{
    /** @var Module */
    private $module;

    /** @var array<int, string> */
    private $confirmations = array();

    /** @var array<int, string> */
    private $errors = array();

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @return string the rendered page
     */
    public function render()
    {
        $this->handlePost();

        // `Module::$context` is PROTECTED, so this class cannot borrow the module's
        // copy — and the global singleton is the same object anyway.
        Context::getContext()->smarty->assign($this->viewData());

        return $this->module->display(
            $this->module->getLocalPath() . 'nitrosearch.php',
            'views/templates/admin/configure.tpl'
        );
    }

    /**
     * Dispatch whichever button was pressed.
     *
     * EVERY ACTION IS A POST AND EVERY POST IS TOKEN-CHECKED. PrestaShop's admin
     * token is in the page URL and rides the form, so a plain check is enough —
     * but without it, "disconnect this shop" and "re-sync the whole catalogue"
     * would both be reachable by getting a logged-in merchant to load an image
     * tag. Sync actions are the load-bearing case: a forged full sync is a way to
     * make someone's server work hard, repeatedly.
     */
    private function handlePost()
    {
        $actions = array(
            'submitNitroConnect' => 'connect',
            'submitNitroVerify' => 'verify',
            'submitNitroStatus' => 'checkStatus',
            'submitNitroFullSync' => 'fullSync',
            'submitNitroDrain' => 'drainNow',
            'submitNitroDisconnect' => 'disconnect',
            'submitNitroSettings' => 'saveSettings',
        );

        foreach ($actions as $submit => $method) {
            if (!Tools::isSubmit($submit)) {
                continue;
            }

            if (!$this->tokenIsValid()) {
                $this->errors[] = $this->l('Your session has expired. Please reload this page and try again.');

                return;
            }

            $this->{$method}();

            return;
        }
    }

    /**
     * @return bool
     */
    private function tokenIsValid()
    {
        $provided = (string) Tools::getValue('nitro_token');

        return $provided !== '' && hash_equals(Tools::getAdminTokenLite('AdminModules'), $provided);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    private function connect()
    {
        // Saved BEFORE connecting: a merchant on a self-hosted or staging service
        // has to be able to point at it, and connect() reads these.
        $this->saveConnectionSettings();

        $result = Client::connect();

        if (empty($result['ok'])) {
            $this->errors[] = sprintf(
                $this->l('Connect failed: %s'),
                isset($result['error']) ? Tools::substr((string) $result['error'], 0, 300) : ''
            );

            return;
        }

        $this->confirmations[] = $this->l('Connected. Confirming that you control this domain…');

        // Verification is attempted straight away rather than left for the
        // merchant to press: on a publicly reachable shop it just works, and
        // nothing else can happen until it does.
        $this->verify(false);

        if ((bool) Settings::get('VERIFIED')) {
            $this->startFirstSync();
        }
    }

    /**
     * @param bool $announce whether to report the outcome (suppressed when called
     *                       from connect(), which reports its own)
     */
    private function verify($announce = true)
    {
        if (!Settings::isConnected()) {
            $this->errors[] = $this->l('Connect the shop first.');

            return;
        }

        // ASK BEFORE ACTING. The service is the authority on whether this shop is
        // confirmed, and it may well have confirmed it WITHOUT us: the check is a
        // request it makes to this shop from the outside, so it can succeed at a
        // moment we know nothing about. Polling status first is one cheap read that
        // resolves the common case; triggering another outside request when the
        // answer is already yes would just be slower and less reliable.
        Settings::update(array('STATUS_CHECKED_AT' => 0));
        $status = Client::status();

        if (!empty($status['ok']) && !empty($status['verified'])) {
            Client::fetchSearchKey();
            $this->confirmations[] = $this->l('Domain confirmed. Search is ready to use.');

            if (!FullSync::isActive() && Outbox::total() === 0) {
                $this->startFirstSync();
            }

            return;
        }

        $result = Client::verify();

        if (!empty($result['verified'])) {
            Client::fetchSearchKey();
            $this->confirmations[] = $this->l('Domain confirmed. Search is ready to use.');
            $this->startFirstSync();

            return;
        }

        if ($announce) {
            // Deliberately not phrased as a failure. The overwhelmingly common
            // cause is a shop that is not reachable from the public internet yet —
            // localhost, a staging host, a firewall, HTTP auth — which is not
            // broken, just not ready.
            $this->errors[] = $this->l('We could not reach this shop from the outside yet, so it is not confirmed. This is normal for a shop that is not publicly live. Try again once it is reachable.');
        }
    }

    private function checkStatus()
    {
        if (!Settings::isConnected()) {
            $this->errors[] = $this->l('Connect the shop first.');

            return;
        }

        // Forced: the periodic check is rate-limited to one poll every five
        // minutes, and a merchant who has just pressed a button expects an answer
        // now rather than whenever the interval happens to elapse.
        Settings::update(array('STATUS_CHECKED_AT' => 0));
        $status = Client::status();

        if (empty($status['ok'])) {
            $this->errors[] = $this->l('Could not reach NitroSearch. Your catalogue is safe — the sync will retry on its own.');

            return;
        }

        $this->confirmations[] = $this->l('Status updated.');
    }

    private function fullSync()
    {
        if (!$this->requireVerified()) {
            return;
        }

        $total = FullSync::start();
        $this->confirmations[] = sprintf(
            $this->l('Re-sending your catalogue — about %d items. This runs in the background; you can leave this page.'),
            (int) $total
        );
    }

    private function drainNow()
    {
        if (!$this->requireVerified()) {
            return;
        }

        $result = Drain::run();

        if ($result['stopped'] === 'error') {
            $this->errors[] = sprintf(
                $this->l('Sending stopped on an error: %s'),
                Tools::substr((string) Settings::get('LAST_ERROR'), 0, 300)
            );

            return;
        }

        $this->confirmations[] = sprintf(
            $this->l('Sent %1$d items. %2$d still queued.'),
            (int) $result['items'],
            Outbox::pendingCount()
        );
    }

    private function disconnect()
    {
        FullSync::cancel();
        Outbox::truncate();
        Settings::disconnect();

        $this->confirmations[] = $this->l('Disconnected. Your products and pages are untouched.');
    }

    private function saveSettings()
    {
        $wasIndexingCms = (bool) Settings::get('INDEX_CMS');

        $this->saveConnectionSettings();

        $indexCms = (bool) Tools::getValue('nitro_index_cms');

        Settings::update(array(
            'INDEX_CMS' => $indexCms,
            'RESULTS_TAKEOVER' => (bool) Tools::getValue('nitro_results_takeover'),
            'SUPPRESS_NATIVE_SEARCH' => (bool) Tools::getValue('nitro_suppress_native'),
            'SHOW_BADGE' => (bool) Tools::getValue('nitro_show_badge'),
            'SHARE_SEARCH_DATA' => (bool) Tools::getValue('nitro_share_search_data'),
            'SELECTOR' => trim((string) Tools::getValue('nitro_selector')),
        ));

        $this->confirmations[] = $this->l('Settings saved.');

        if ($indexCms && !$wasIndexingCms && Settings::isConnected()) {
            // Walk ONLY the pages. Re-walking the whole catalogue to add a handful
            // of CMS pages would put every product back through this shop's own
            // server for nothing.
            FullSync::start(array('page'));
            $this->confirmations[] = $this->l('Adding your pages to the index now.');
        }
    }

    /**
     * The two fields that decide WHERE we connect. Split out because connect()
     * needs them saved before it runs.
     */
    private function saveConnectionSettings()
    {
        $apiUrl = trim((string) Tools::getValue('nitro_api_url'));
        $update = array();

        if ($apiUrl !== '') {
            $update['API_URL'] = rtrim($apiUrl, '/');
        }

        // Only overwrite the provisioning token when something was actually typed:
        // the field renders empty (it is a credential), so treating blank as
        // "clear it" would wipe a working token every time any setting is saved.
        $connectToken = trim((string) Tools::getValue('nitro_connect_token'));
        if ($connectToken !== '') {
            $update['CONNECT_TOKEN'] = $connectToken;
        }

        if (!empty($update)) {
            Settings::update($update);
        }
    }

    private function startFirstSync()
    {
        $total = FullSync::start();
        $this->confirmations[] = sprintf(
            $this->l('Indexing your catalogue — about %d items. This runs in the background.'),
            (int) $total
        );
    }

    /**
     * @return bool
     */
    private function requireVerified()
    {
        if (!Settings::isConnected()) {
            $this->errors[] = $this->l('Connect the shop first.');

            return false;
        }

        if (!(bool) Settings::get('VERIFIED')) {
            $this->errors[] = $this->l('This shop is not confirmed yet, so nothing can be indexed. Use "Try again" above once the shop is reachable from the internet.');

            return false;
        }

        return true;
    }

    // ── View data ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function viewData()
    {
        $connected = Settings::isConnected();
        $verified = $connected && (bool) Settings::get('VERIFIED');

        $limit = (int) Settings::get('PRODUCT_LIMIT');
        $count = (int) Settings::get('PRODUCT_COUNT');

        $fullSync = FullSync::state();

        return array(
            'nitro_confirmations' => $this->confirmations,
            'nitro_errors' => $this->errors,
            'nitro_token' => Tools::getAdminTokenLite('AdminModules'),
            'nitro_action_url' => Context::getContext()->link->getAdminLink('AdminModules', true)
                . '&configure=' . $this->module->name,

            // One value the template branches on, rather than three booleans it
            // has to combine correctly in several places.
            'nitro_state' => $connected ? ($verified ? 'ready' : 'unverified') : 'disconnected',

            'nitro_plan' => (string) Settings::get('PLAN'),
            'nitro_claimed' => (bool) Settings::get('CLAIMED'),
            'nitro_limit' => $limit,
            'nitro_count' => $count,
            'nitro_at_limit' => (bool) Settings::get('AT_LIMIT'),
            'nitro_usage_pct' => $limit > 0 ? min(100, (int) round(($count / $limit) * 100)) : 0,

            'nitro_pending' => $connected ? Outbox::pendingCount() : 0,
            'nitro_queue_total' => $connected ? Outbox::total() : 0,
            'nitro_last_sync' => (string) Settings::get('LAST_SYNC'),
            'nitro_last_error' => (string) Settings::get('LAST_ERROR'),
            'nitro_avg_batch_ms' => (int) Settings::get('AVG_BATCH_MS'),
            'nitro_items_total' => (int) Settings::get('SYNC_ITEMS_TOTAL'),

            'nitro_full_sync_active' => $fullSync['active'],
            'nitro_full_sync_phase' => $fullSync['phase'],
            'nitro_full_sync_total' => $fullSync['total'],

            'nitro_cron_url' => $this->module->cronUrl(),
            'nitro_drain_ran_at' => (int) Settings::get('DRAIN_RAN_AT'),

            // Settings
            'nitro_api_url' => Settings::apiUrl(),
            'nitro_has_connect_token' => (string) Settings::get('CONNECT_TOKEN') !== '',
            'nitro_index_cms' => (bool) Settings::get('INDEX_CMS'),
            'nitro_results_takeover' => (bool) Settings::get('RESULTS_TAKEOVER'),
            'nitro_suppress_native' => (bool) Settings::get('SUPPRESS_NATIVE_SEARCH'),
            'nitro_show_badge' => (bool) Settings::get('SHOW_BADGE'),
            'nitro_share_search_data' => (bool) Settings::get('SHARE_SEARCH_DATA'),
            'nitro_selector' => (string) Settings::get('SELECTOR'),

            // Presence, never the value — see the class docblock.
            'nitro_has_search_key' => (string) Settings::get('SCOPED_SEARCH_KEY') !== '',
            'nitro_store_id' => (string) Settings::get('STORE_ID'),
        );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function l($string)
    {
        // The translator directly, NOT $module->trans(): `Module::trans()` is
        // protected, so this class cannot call it. Same catalogue, same result.
        return Context::getContext()->getTranslator()->trans(
            $string,
            array(),
            'Modules.Nitrosearch.Admin'
        );
    }
}
