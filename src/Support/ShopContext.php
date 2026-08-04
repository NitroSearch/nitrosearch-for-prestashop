<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Context;
use Currency;
use Shop;
use Validate;

/**
 * Which shop, which language and which currency this module indexes.
 *
 * ONE NITROSEARCH STORE IS ONE SHOP, ONE LANGUAGE AND ONE CURRENCY. That is the
 * ingest contract's own limit, stated in it: a storefront serving several
 * currencies is not supported yet. PrestaShop happily does all three at once, so
 * this class is where the mismatch is resolved — deliberately and in one place,
 * rather than by whatever the ambient request context happened to hold.
 *
 * THAT AMBIENT CONTEXT IS THE BUG THIS CLASS EXISTS TO PREVENT. Reading
 * `Context::getContext()->currency` looks obvious and is wrong for a background
 * sync: it is the SHOPPER's currency in a front-office request, the employee's in
 * the back office, and the default in cron. The same product would then be
 * indexed at £29 by a page-load drain and €29 by a cron drain, and the number a
 * shopper sees in search would depend on who happened to trigger the sync. The
 * shop's configured DEFAULT is the only answer that is the same every time.
 */
final class ShopContext
{
    /**
     * The currency every indexed price is expressed in.
     *
     * @return string ISO 4217
     */
    public static function currencyIso()
    {
        $id = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        if ($id > 0) {
            $currency = new Currency($id);
            if (Validate::isLoadedObject($currency) && $currency->iso_code) {
                return strtoupper((string) $currency->iso_code);
            }
        }

        // Only reached on a shop with no default currency configured, which
        // PrestaShop does not normally allow.
        $context = Context::getContext();
        if ($context->currency && $context->currency->iso_code) {
            return strtoupper((string) $context->currency->iso_code);
        }

        return 'EUR';
    }

    /**
     * The language products and pages are indexed in.
     *
     * Same reasoning as the currency: the ambient language is the visitor's, and
     * a sync must not index French names because a French shopper's page load
     * happened to trigger the drain.
     *
     * @return int
     */
    public static function languageId()
    {
        $id = (int) Configuration::get('PS_LANG_DEFAULT');

        return $id > 0 ? $id : (int) Context::getContext()->language->id;
    }

    /**
     * @return int the shop whose catalogue is indexed
     */
    public static function shopId()
    {
        $id = (int) Configuration::get('PS_SHOP_DEFAULT');

        return $id > 0 ? $id : (int) Context::getContext()->shop->id;
    }

    /**
     * @return bool whether this install runs more than one shop
     */
    public static function isMultistore()
    {
        return Shop::isFeatureActive() && count(Shop::getShops(true)) > 1;
    }

    /**
     * @return array<int, string> the names of every shop that is NOT indexed
     */
    public static function unindexedShops()
    {
        if (!self::isMultistore()) {
            return array();
        }

        $indexed = self::shopId();
        $names = array();
        foreach (Shop::getShops(true) as $shop) {
            if ((int) $shop['id_shop'] !== $indexed) {
                $names[] = (string) $shop['name'];
            }
        }

        return $names;
    }

    /**
     * @return array<int, string> ISO codes of active currencies that are NOT indexed
     */
    public static function unindexedCurrencies()
    {
        $indexed = self::currencyIso();
        $others = array();

        foreach (Currency::getCurrencies(false, true) as $currency) {
            $iso = strtoupper((string) $currency['iso_code']);
            if ($iso !== '' && $iso !== $indexed) {
                $others[] = $iso;
            }
        }

        return $others;
    }

    /**
     * Pin the context so a background run indexes the same shop a front-office
     * one does.
     *
     * PrestaShop resolves a product's price, categories and availability against
     * the ambient shop, so a cron run with no shop context can silently read a
     * different catalogue on a multistore install.
     */
    public static function pin()
    {
        if (!Shop::isFeatureActive()) {
            return;
        }

        $shopId = self::shopId();
        if ($shopId > 0 && (int) Context::getContext()->shop->id !== $shopId) {
            Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
        }
    }
}
