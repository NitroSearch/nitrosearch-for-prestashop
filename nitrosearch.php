<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    WebDeviAnt Studios
 * @copyright WebDeviAnt Studios
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

use NitroSearch\Api\Client;
use NitroSearch\Settings;
use NitroSearch\Sync\Drain;
use NitroSearch\Sync\FullSync;
use NitroSearch\Sync\Outbox;

/**
 * NitroSearch — fast, typo-tolerant search for PrestaShop.
 *
 * The module has four jobs and nothing else:
 *
 *  1. Connect the shop to the service and hold its credentials.
 *  2. Record catalogue changes in a local outbox, cheaply, from hooks.
 *  3. Drain that outbox to the service in signed batches, politely.
 *  4. Put the storefront widget on the shop's own search box.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is search. Shopper queries go straight from
 * the browser to the search engine with a scoped, read-only key — never back
 * through PHP. A shop's search stays fast even while its own server is busy, and
 * this module is not on that path at all.
 */
class NitroSearch extends Module
{
    public function __construct()
    {
        $this->name = 'nitrosearch';
        $this->tab = 'search_filter';
        $this->version = '1.0.0';
        $this->author = 'WebDeviAnt Studios';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('NitroSearch', array(), 'Modules.Nitrosearch.Admin');
        $this->description = $this->trans(
            'Fast, typo-tolerant search for your shop. Results appear as your shoppers type.',
            array(),
            'Modules.Nitrosearch.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'This will disconnect the shop and delete the local sync queue. Your products are not affected.',
            array(),
            'Modules.Nitrosearch.Admin'
        );
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!Db::getInstance()->execute(Outbox::schema())) {
            return false;
        }

        foreach ($this->hookList() as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        // A per-install cron token, minted once. Never derived from the shop URL
        // or the install id — both are discoverable, and a guessable token makes
        // the cron endpoint an unauthenticated way to load someone's server.
        Settings::update(array('DRAIN_TOKEN' => bin2hex(random_bytes(16))));
        Settings::installId();

        return true;
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        FullSync::cancel();
        Outbox::drop();
        Settings::purge();

        return parent::uninstall();
    }

    /**
     * Every hook the module listens on.
     *
     * THE LIST IS LONG ON PURPOSE, AND STILL NOT TRUSTED. PrestaShop fires
     * different hooks depending on how a product was changed — the back office
     * form, a bulk action, the CSV importer, a webservice call and a third-party
     * module all take different paths, and some take none we can see. Registering
     * broadly catches most changes promptly; the periodic full walk
     * ({@see FullSync}) is what makes the sync CORRECT, because it does not depend
     * on any of these firing.
     *
     * @return array<int, string>
     */
    private function hookList()
    {
        return array(
            // ObjectModel generic hooks — the most reliable, because they fire from
            // ObjectModel::add/update/delete itself rather than from a controller.
            'actionObjectProductAddAfter',
            'actionObjectProductUpdateAfter',
            'actionObjectProductDeleteAfter',
            'actionObjectCmsAddAfter',
            'actionObjectCmsUpdateAfter',
            'actionObjectCmsDeleteAfter',
            // Combinations change a product's variants, its price range and its
            // facets without touching the product row itself.
            'actionObjectCombinationAddAfter',
            'actionObjectCombinationUpdateAfter',
            'actionObjectCombinationDeleteAfter',
            // Stock. Fires on orders too, which is exactly when in-stock badges go
            // stale and the reason this is worth listening to.
            'actionUpdateQuantity',
            // Storefront: the widget markup, and the no-cron drain fallback.
            // `displayHeader` rather than `actionFrontControllerSetMedia` because
            // the config must be emitted INLINE, immediately before the loader —
            // setMedia can register a file but cannot place a literal script block,
            // and the loader reads the config at parse time.
            'displayHeader',
        );
    }

    // ── Catalogue change hooks ────────────────────────────────────────────────
    //
    // Each does exactly one thing: write a coalesced row to the outbox. No HTTP,
    // no payload building, no product hydration. That is what keeps a save, a
    // bulk edit and a checkout fast, and what lets the shop keep recording
    // changes while the service is unreachable.

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectProductAddAfter($params)
    {
        $this->queueProduct($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->queueProduct($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectProductDeleteAfter($params)
    {
        if (isset($params['object']) && (int) $params['object']->id) {
            Outbox::enqueue('product', (int) $params['object']->id, 'delete');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCmsAddAfter($params)
    {
        $this->queueCms($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCmsUpdateAfter($params)
    {
        $this->queueCms($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCmsDeleteAfter($params)
    {
        if (isset($params['object']) && (int) $params['object']->id) {
            Outbox::enqueue('page', (int) $params['object']->id, 'delete');
        }
    }

    /**
     * A combination changed, so its PARENT product is what needs re-sending.
     *
     * Combinations are never top-level objects on the wire — one product is one
     * indexed object and one unit of the merchant's plan, however many
     * combinations it has.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->queueCombinationParent($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->queueCombinationParent($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->queueCombinationParent($params);
    }

    /**
     * Stock moved.
     *
     * This fires on ORDERS as well as manual edits, which is the case worth having
     * it for: a product selling out is precisely when an "in stock" badge in
     * search results becomes a lie.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionUpdateQuantity($params)
    {
        if (isset($params['id_product']) && (int) $params['id_product']) {
            Outbox::enqueue('product', (int) $params['id_product'], 'upsert');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queueProduct($params)
    {
        if (isset($params['object']) && (int) $params['object']->id) {
            Outbox::enqueue('product', (int) $params['object']->id, 'upsert');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queueCms($params)
    {
        if (!(bool) Settings::get('INDEX_CMS')) {
            return;
        }
        if (isset($params['object']) && (int) $params['object']->id) {
            Outbox::enqueue('page', (int) $params['object']->id, 'upsert');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function queueCombinationParent($params)
    {
        if (!isset($params['object'])) {
            return;
        }

        $parentId = (int) $params['object']->id_product;
        if ($parentId) {
            Outbox::enqueue('product', $parentId, 'upsert');
        }
    }

    // ── Storefront ────────────────────────────────────────────────────────────

    /**
     * Emit the widget into `<head>`, and take the opportunity to drain.
     *
     * @return string markup, or '' when the shop is not ready to search
     */
    public function hookDisplayHeader()
    {
        // The no-cron fallback. It returns immediately unless the interval has
        // elapsed AND there is work, and defers the actual send until after the
        // shopper's page has been flushed — so this costs a page view nothing.
        Drain::tick();

        return $this->widgetMarkup();
    }

    /**
     * `window.NitroSearchConfig` followed by the loader.
     *
     * The loader enhances the theme's existing search input in place and only
     * fetches the widget bundle on the shopper's first search intent, so a visitor
     * who never searches downloads ~1.3 KB and nothing else.
     *
     * @return string
     */
    private function widgetMarkup()
    {
        if (!Settings::isConnected()) {
            return '';
        }

        $scopedKey = (string) Settings::get('SCOPED_SEARCH_KEY');
        $engineHost = (string) Settings::get('ENGINE_HOST');
        if ($scopedKey === '' || $engineHost === '') {
            // Not verified yet, so there is no key. Emitting the loader anyway
            // would put a script on every page that can only fail.
            return '';
        }

        $loaderUrl = (string) Settings::get('WIDGET_LOADER_URL');
        $bundleUrl = (string) Settings::get('WIDGET_BUNDLE_URL');
        if ($loaderUrl === '' || $bundleUrl === '') {
            return '';
        }

        $config = array(
            'engine' => array('host' => $engineHost),
            'collection' => (string) Settings::get('COLLECTION'),
            'scopedKey' => $scopedKey,
            'bundleUrl' => $bundleUrl,
            'siteUrl' => Client::shopUrl(),
            'currency' => $this->currencyIso(),
            'locale' => str_replace('_', '-', (string) $this->context->language->locale),
            'results' => (bool) Settings::get('RESULTS_TAKEOVER'),
            'content' => (bool) Settings::get('INDEX_CMS'),
            'analytics' => (bool) Settings::get('SHARE_SEARCH_DATA'),
            'badge' => (bool) Settings::get('SHOW_BADGE'),
        );

        if ((bool) Settings::get('SHOW_BADGE')) {
            $config['badgeUrl'] = 'https://nitrosearch.io';
        }

        $selector = trim((string) Settings::get('SELECTOR'));
        if ($selector !== '') {
            $config['selector'] = $selector;
        }

        $eventsToken = (string) Settings::get('EVENTS_TOKEN');
        $eventsUrl = (string) Settings::get('EVENTS_URL');
        if ($eventsToken !== '' && $eventsUrl !== '') {
            $config['events'] = array('url' => $eventsUrl, 'token' => $eventsToken);
        }

        // NOTE: no `cart` key, deliberately. The widget reads PrestaShop's own
        // per-visitor static token and cart URL from `window.prestashop`, which the
        // theme publishes on every front-office page — so add-to-cart works without
        // this module handing it anything, and without a per-visitor token being
        // baked into a page that may be cached and served to someone else. Setting
        // `cart` here overrides that, and is only wanted by a shop that has
        // genuinely moved its cart endpoint.

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        // `</script>` inside a JSON string would close this block early — the
        // classic inline-JSON escape. Nothing in the config should contain it, but
        // the shop's own name and a merchant-supplied selector both reach here, and
        // "should not" is not a security property.
        $json = str_replace('<', '<', $json);

        return '<script>window.NitroSearchConfig=' . $json . ';</script>' . "\n"
            . '<script src="' . htmlspecialchars($loaderUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
    }

    /**
     * @return string
     */
    private function currencyIso()
    {
        if ($this->context->currency && $this->context->currency->iso_code) {
            return Tools::strtoupper((string) $this->context->currency->iso_code);
        }

        return 'EUR';
    }

    /**
     * @return string the URL a merchant points their cron at
     */
    public function cronUrl()
    {
        return Client::shopUrl()
            . '/index.php?fc=module&module=nitrosearch&controller=cron&token='
            . (string) Settings::get('DRAIN_TOKEN');
    }
}
