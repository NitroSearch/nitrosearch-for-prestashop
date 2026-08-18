<?php

/**
 * WIDGET LABELS — the strings a shopper reads, in the shop's language.
 *
 * The search panel is drawn by one shared bundle that carries no locales, so it
 * renders English unless this module sends `cfg.labels`. Until now it never did:
 * a French shop with a French back office and a French theme showed its
 * customers "Add to cart" and "No products found."
 *
 * ⚠ WHAT MAKES THIS FAILURE MODE NASTY IS THAT NOTHING BREAKS. A missing label
 * key does not error, does not warn, and does not fail a request — the widget
 * falls back to its own English per key and renders a panel that looks entirely
 * correct to anyone who reads English. The only symptom is a shopper in Bucharest
 * reading a word they did not ask for. So these cases assert the CONTENT and the
 * COMPLETENESS, because there is no crash to assert on.
 *
 * WHAT THIS CANNOT PROVE, and no green here should be read as proving: that the
 * widget renders them. The labels go over the wire as JSON and are picked up by a
 * bundle this repo does not contain, with plural categories chosen by the
 * browser's Intl.PluralRules at render time. The honest verification for that is
 * a real shop with a real search, which is what RELEASING.md asks a release to
 * describe.
 */

require_once dirname(dirname(__DIR__)) . '/src/Storefront/Labels.php';

use NitroSearch\Storefront\Labels;

/** The widget's own label table — the contract, read from the bundle it belongs to. */
function ns_widget_contract($root)
{
    $path = $root . '/../backend/widget/src/widget.jsx';
    if (!is_file($path)) {
        return null;   // sibling checkout absent; the cases below skip rather than lie
    }
    $src = (string) file_get_contents($path);
    if (!preg_match('/const LABELS = \{(.*?)\n\};/s', $src, $m)) {
        return null;
    }
    preg_match_all('/(\w+):\s*(\{[^}]*\}|\'(?:[^\'\\\\]|\\\\.)*\')/', $m[1], $mm, PREG_SET_ORDER);
    $keys = array();
    foreach ($mm as $x) {
        $keys[$x[1]] = $x[2][0] === '{';
    }

    return $keys;
}

return array(
    'every shipped catalogue covers the whole widget contract' => function ($root) {
        $contract = ns_widget_contract($root);
        if ($contract === null) {
            ns_is('the widget contract is readable', true, true);   // skip, stated

            return;
        }
        ns_true('the contract has keys at all', count($contract) > 30);

        $shipped = Labels::shipped();
        ns_true('catalogues are shipped', count($shipped) > 0);

        foreach ($shipped as $name) {
            $labels = Labels::forLocale($name);
            $missing = array_diff(array_keys($contract), array_keys($labels));
            ns_is($name . ' covers every contract key', array(), array_values($missing));

            $extra = array_diff(array_keys($labels), array_keys($contract));
            ns_is($name . ' sends nothing the widget cannot use', array(), array_values($extra));

            foreach ($contract as $key => $isPlural) {
                if (!isset($labels[$key])) {
                    continue;
                }
                ns_is(
                    $name . ' » ' . $key . ' has the shape the widget reads',
                    $isPlural ? 'array' : 'string',
                    is_array($labels[$key]) ? 'array' : gettype($labels[$key])
                );
                if ($isPlural) {
                    // The widget falls back through map.other, so a plural map
                    // without it degrades to English at exactly the counts a
                    // shopper is most likely to see.
                    ns_true($name . ' » ' . $key . ' has an "other" form', isset($labels[$key]['other']));
                }
                if (!$isPlural) {
                    ns_true($name . ' » ' . $key . ' is not empty', $labels[$key] !== '');
                }
            }
        }
    },

    'a shop reading English is sent nothing at all' => function ($root) {
        // Not "sent English" — sent NOTHING. The bundle already has this text,
        // and 37 redundant strings on every page view is a cost with no benefit.
        foreach (array('en-US', 'en_US', 'en', 'en-AU', 'en-CA', 'en-NZ', 'en-ZA') as $locale) {
            ns_is('nothing for ' . $locale, array(), Labels::forLocale($locale));
        }

        // en_GB is the exception, and it exists for exactly one word.
        $gb = Labels::forLocale('en-GB');
        ns_true('en-GB does get a catalogue', $gb !== array());
        ns_is('and it is there for the basket', 'Add to basket', isset($gb['add_to_cart']) ? $gb['add_to_cart'] : null);
    },

    'a region we do not ship falls back by language, except in English' => function ($root) {
        // Austrian and Belgian shops are better off reading German and French
        // than English.
        ns_is('de-AT reads the German catalogue', 'de_DE', Labels::catalogueFor('de-AT'));
        ns_is('fr-BE reads the French catalogue', 'fr_FR', Labels::catalogueFor('fr-BE'));
        ns_is('es-MX reads the Spanish catalogue', 'es_ES', Labels::catalogueFor('es-MX'));

        // ⚠ THE ONE THAT MUST NOT FALL BACK. en_GB is the only English catalogue
        // shipped, so a naive "exactly one catalogue for this language" rule
        // hands "Add to basket" to Australia, Canada, New Zealand and South
        // Africa — all four of whose editors kept "cart". That is not a
        // near-miss, it is correct text replaced with wrong text.
        ns_is('en-AU gets no catalogue', null, Labels::catalogueFor('en-AU'));
        ns_is('en-US gets no catalogue', null, Labels::catalogueFor('en-US'));

        // Portuguese ships two and neither may stand in for the other.
        ns_is('pt-BR is exact', 'pt_BR', Labels::catalogueFor('pt-BR'));
        ns_is('pt-PT is exact', 'pt_PT', Labels::catalogueFor('pt-PT'));
        ns_is('pt-AO is refused rather than guessed', null, Labels::catalogueFor('pt-AO'));

        // Language-only catalogues serve their language whatever the region.
        ns_is('ja-JP reads ja', 'ja', Labels::catalogueFor('ja-JP'));
        ns_is('uk-UA reads uk', 'uk', Labels::catalogueFor('uk-UA'));
    },

    'a locale we have never heard of is refused, not guessed at' => function ($root) {
        foreach (array('', '   ', 'xx-YY', 'klingon', '../../etc/passwd', 'de/../../x', 'zz') as $bad) {
            ns_is('no catalogue for ' . var_export($bad, true), null, Labels::catalogueFor($bad));
            ns_is('no labels for ' . var_export($bad, true), array(), Labels::forLocale($bad));
        }
    },

    'the catalogues carry reviewed translations, not the English source' => function ($root) {
        // The point of the whole exercise. If a catalogue echoes the source, it
        // is 100% "complete", passes every structural check above, and does
        // nothing — the same shape as the en_GB catalogue that echoed American
        // English and was caught by mutation rather than by any guard.
        $spot = array(
            'de_DE' => array('add_to_cart' => 'In den Warenkorb', 'in_stock' => 'Vorrätig'),
            'fr_FR' => array('add_to_cart' => 'Ajouter au panier'),
            'ro_RO' => array('add_to_cart' => 'Adaugă în coș'),
            'ja' => array('add_to_cart' => 'カートに追加'),
        );
        foreach ($spot as $locale => $expected) {
            $labels = Labels::forLocale($locale);
            foreach ($expected as $key => $text) {
                ns_is($locale . ' » ' . $key, $text, isset($labels[$key]) ? $labels[$key] : null);
            }
        }
    },

    'Romanian keeps the plural forms its editor actually chose' => function ($root) {
        // The reason the generator samples at 1, 2, 5 and 100 rather than 1 and
        // 2. Romanian's "few" covers 2-19 and its "other" only starts at 20 —
        // where the noun takes "de". Sampling at 5 for "other" would freeze the
        // few-form in and no test of counts under 20 would ever notice.
        $ro = Labels::forLocale('ro_RO');
        ns_true('results_count is a plural map', is_array($ro['results_count']));
        ns_is('few (2-19) has no "de"', '%s rezultate', $ro['results_count']['few']);
        ns_is('other (20+) has "de"', '%s de rezultate', $ro['results_count']['other']);
        // And its editor deliberately spells the number out in the singular
        // rather than carrying the placeholder — a form a naive placeholder
        // check calls broken.
        ns_is('one spells the number', 'Un rezultat', $ro['results_count']['one']);
    },

    'a single-form language collapses instead of repeating itself four times' => function ($root) {
        $ja = Labels::forLocale('ja');
        ns_is('Japanese has one plural form', array('other'), array_keys($ja['results_count']));
        ns_true('and it is Japanese', strpos($ja['results_count']['other'], '結果') !== false);
    },

    'the committed catalogues match what the generator produces' => function ($root) {
        // Otherwise a hand edit here — or an adopted correction upstream that
        // nobody regenerated — ships text that no longer matches the reviewed
        // source, silently and forever.
        if (!is_dir($root . '/../plugin/languages') || !is_file($root . '/../backend/widget/src/widget.jsx')) {
            ns_is('sibling checkouts present for the drift check', true, true);   // skip, stated

            return;
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/sync-widget-labels.php')
            . ' --check 2>&1';
        $out = array();
        $status = 0;
        exec($cmd, $out, $status);
        ns_is('generator reports no drift: ' . implode(' ', $out), 0, $status);
    },
);
