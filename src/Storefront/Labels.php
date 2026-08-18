<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Storefront;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The storefront search panel's own strings, in the shop's language.
 *
 * WHAT THIS FIXES. The panel a shopper sees is drawn by one shared widget
 * bundle that serves every store on every platform, so it carries no locales:
 * it renders its built-in English unless the module hands it `cfg.labels`. A
 * French shop, with a French back office and a French theme, showed its
 * shoppers "Add to cart", "In stock" and "No products found." — the module was
 * translating its own admin screen and leaving the customer-facing half in
 * English.
 *
 * WHERE THE WORDS COME FROM. `bin/sync-widget-labels.php` derives the
 * catalogues in `labels/` from the WooCommerce plugin's gettext catalogues —
 * the same 37 English strings, already translated into 23 shipping locales,
 * already natively reviewed, and in seven of them corrected by that locale's
 * own wordpress.org translation editor. They are generated and committed; a
 * shop never runs the generator.
 *
 * WHAT IS DELIBERATELY NOT HERE. No call into PrestaShop's translator. These
 * are not module strings a merchant translates in the back office — they are
 * one contract with one consumer, including four plural maps whose CLDR
 * categories are resolved at generation time. Routing them through `trans()`
 * would mean asking a 1.7.6 shop and an 8.x shop the same pluralisation
 * question and getting two different mechanisms back, to arrive at text we
 * already have.
 */
class Labels
{
    /**
     * The language the widget bundle is already written in.
     *
     * ⚠ THIS IS WHY ENGLISH NEVER FALLS BACK BY LANGUAGE. Every other language
     * may: a de-AT shop reading the de_DE catalogue, or fr-BE reading fr_FR, is
     * unambiguously better off than reading English. English is the one case
     * where the bundle's own text is already right for most regions and the
     * catalogue we ship is the exception — en_GB exists to say "Add to basket",
     * a word en_AU, en_CA, en_NZ and en_ZA all measurably reject. Falling
     * en-AU back to en_GB would not be a near-miss; it would replace correct
     * text with wrong text.
     */
    const SOURCE_LANGUAGE = 'en';

    /**
     * @param string $psLocale the shop language's locale, e.g. 'de-DE', 'pt-BR'
     *
     * @return array<string,string|array<string,string>> empty when we have
     *                                                   nothing better than the
     *                                                   widget's own English
     */
    public static function forLocale($psLocale)
    {
        $name = self::catalogueFor($psLocale);
        if ($name === null) {
            return array();
        }

        $labels = include __DIR__ . '/labels/' . $name . '.php';

        return is_array($labels) ? $labels : array();
    }

    /**
     * Which shipped catalogue serves this locale, if any.
     *
     * @param string $psLocale
     *
     * @return string|null
     */
    public static function catalogueFor($psLocale)
    {
        $locale = str_replace('-', '_', trim((string) $psLocale));
        if (!preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z0-9]{2,4}))?$/', $locale, $m)) {
            return null;
        }

        $language = strtolower($m[1]);
        $region = isset($m[2]) ? strtoupper($m[2]) : '';
        $shipped = self::shipped();

        // Exact first: pt-BR and pt-PT are different catalogues and neither may
        // stand in for the other.
        $exact = $region === '' ? $language : $language . '_' . $region;
        foreach ($shipped as $name) {
            if (strcasecmp($name, $exact) === 0) {
                return $name;
            }
        }

        if ($language === self::SOURCE_LANGUAGE) {
            return null;   // see SOURCE_LANGUAGE
        }

        // Otherwise the language alone, but only when exactly one catalogue
        // claims it. Two would be a guess between regions, and a guess here
        // reads as a translation error rather than a missing translation.
        $candidates = array();
        foreach ($shipped as $name) {
            if (strcasecmp(strtok($name, '_'), $language) === 0) {
                $candidates[] = $name;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The catalogues actually present on disk.
     *
     * Read from the directory rather than declared in a list here: the
     * generator decides which locales earn a catalogue — one that resolves
     * every string to the widget's own English is not shipped — and a second
     * list in this file would be wrong the first time that set changed.
     *
     * @return array<int,string>
     */
    public static function shipped()
    {
        static $names = null;
        if ($names !== null) {
            return $names;
        }

        $names = array();
        foreach ((array) glob(__DIR__ . '/labels/*.php') as $path) {
            $name = basename($path, '.php');
            if ($name !== 'index') {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
