<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

/**
 * THE CONFORMANCE FIXTURES, FINALLY RUN.
 *
 * `tests/fixtures/` has held these since the module was written, and
 * CONTRIBUTING.md has said "run them in CI" for just as long. Nothing ran them:
 * there was no runner and no CI, so twelve files describing exactly how prices
 * and visibility must behave sat in the repo as documentation. Both defects
 * fixed in 1.2.0 were in this area of the module.
 *
 * WHAT THEY ARE. Each fixture is a `wire` payload and the `expected_document`
 * the SERVICE produces from it. The service's projection cannot run here, so
 * this file checks the half that can: that every fixture's own currency and
 * exponent agree with the vendored exponent table this module signs prices with.
 *
 * That is not a tautology. The table is VENDORED — generated elsewhere and
 * copied in — so it can fall behind the service that generated the fixtures, and
 * when it does, this module starts sending JPY as if it had two decimal places.
 * A ¥1,999 kettle becomes ¥19.99. Nothing errors; the price is simply wrong on
 * every storefront running this module.
 */

require_once dirname(dirname(__DIR__)).'/vendor/nitrosearch-contract/src/CurrencyExponents.php';

use NitroSearch\AdapterKit\CurrencyExponents;

return array(
    'every fixture is readable and shaped as expected' => function ($root) {
        $files = glob($root.'/tests/fixtures/*.json');
        $files = array_values(array_filter($files, function ($f) {
            return basename($f) !== 'index.json';
        }));

        // A glob that matches nothing makes every loop below vacuous, and the
        // run would report success having checked no fixture at all.
        ns_true('fixtures were found', count($files) >= 10);

        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);

            ns_true("{$name}: parses as json", is_array($data));
            ns_true("{$name}: has a wire payload", isset($data['wire']));
            ns_true("{$name}: says why it exists", isset($data['why']) && $data['why'] !== '');
        }
    },

    'the vendored exponent table agrees with every fixture that declares one' => function ($root) {
        $files = glob($root.'/tests/fixtures/*.json');
        $checked = 0;

        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);

            if (!isset($data['wire']['currency'], $data['wire']['price_exponent'])) {
                continue; // the legacy fixtures deliberately omit it
            }

            $currency = $data['wire']['currency'];
            $declared = (int) $data['wire']['price_exponent'];

            ns_is(
                "{$name}: exponent for {$currency}",
                $declared,
                CurrencyExponents::for($currency)
            );
            $checked++;
        }

        // The same vacuity trap one level down: if no fixture declared a
        // currency, the loop above asserts nothing and still passes.
        ns_true('at least three currencies were checked', $checked >= 3);
    },

    'the exponent table is not simply two for everything' => function () {
        // The self-negative. A table that returned the default for every input
        // would satisfy every two-decimal fixture above and be catastrophically
        // wrong for the currencies that are not.
        ns_is('JPY has no minor unit', 0, CurrencyExponents::for('JPY'));
        ns_is('KWD has three', 3, CurrencyExponents::for('KWD'));
        ns_is('USD has two', 2, CurrencyExponents::for('USD'));
        ns_is('an unknown code falls back to two', 2, CurrencyExponents::for('ZZZ'));
    },

    'currency lookup is case-insensitive' => function () {
        // PrestaShop's own currency records are not reliably upper-cased, and a
        // lookup that missed would silently return the default — which is right
        // for USD and wrong for JPY, so it would pass most testing.
        ns_is('lowercase jpy', 0, CurrencyExponents::for('jpy'));
    },
);
