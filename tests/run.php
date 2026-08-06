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
 * The module's test runner. No Composer, no PHPUnit, no network, no shop.
 *
 * WHY IT IS HAND-ROLLED. This module ships as a ZIP a merchant uploads, so it has
 * no build step and no Composer dependencies on purpose — a module that resolves
 * dependencies at install time fails on most shared hosts. Adding a dev-only
 * dependency would mean a lockfile, a vendor directory that must not ship, and a
 * packaging rule to keep it out. A hundred lines of runner costs less and cannot
 * leak into the archive.
 *
 * WHAT IT COVERS, DELIBERATELY: the pure, framework-free parts where being wrong
 * is silent and expensive — the HMAC canonicalisation (a drift here is a 401 in
 * production, not a negotiation), the proof-of-control hash, and the currency
 * exponent table that decides whether a price is 19.99 or 1999.00. It does NOT
 * try to test hooks, the outbox or the drain: those need a real PrestaShop, and
 * the honest verification for them is a real shop, which is what CONTRIBUTING
 * asks a pull request to describe.
 *
 *   php tests/run.php
 */

// The module's files guard on `_PS_VERSION_` so they cannot be fetched directly
// over the web. Defining it here is what lets them be loaded at all — the guard
// is the reason a naive `php -r "require …"` against this repo prints nothing and
// exits 0, which looks exactly like a passing test.
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}

$root = dirname(__DIR__);

$passed = 0;
$failures = array();
$currentCase = '';

/**
 * @param string $label
 * @param mixed  $expected
 * @param mixed  $actual
 */
function ns_is($label, $expected, $actual)
{
    global $passed, $failures, $currentCase;

    if ($expected === $actual) {
        $passed++;

        return;
    }

    $failures[] = sprintf(
        "%s › %s\n      expected: %s\n      actual:   %s",
        $currentCase,
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

/**
 * @param string $label
 * @param bool   $condition
 */
function ns_true($label, $condition)
{
    ns_is($label, true, (bool) $condition);
}

/**
 * @param string $label
 * @param bool   $condition
 */
function ns_false($label, $condition)
{
    ns_is($label, false, (bool) $condition);
}

$cases = glob(__DIR__.'/cases/*.php');
sort($cases);

// A runner that finds no cases prints nothing and exits 0, which is
// indistinguishable from a clean run. It has to be an error.
if (!$cases) {
    fwrite(STDERR, "no test cases found under tests/cases/ — the runner is not looking at what it thinks it is\n");
    exit(1);
}

foreach ($cases as $file) {
    $currentCase = basename($file, '.php');
    $tests = require $file;

    if (!is_array($tests) || !$tests) {
        $failures[] = $currentCase.' › the case file returned no tests';
        continue;
    }

    foreach ($tests as $name => $test) {
        $currentCase = basename($file, '.php').' :: '.$name;
        $test($root);
    }
}

if ($failures) {
    fwrite(STDERR, "\n".count($failures)." FAILED\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  ✗ ".$f."\n\n");
    }
    exit(1);
}

fwrite(STDOUT, "ok    {$passed} assertions across ".count($cases)." case file(s)\n");
exit(0);
