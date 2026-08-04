<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A tiny PSR-4 autoloader for this module's two namespaces.
 *
 * WHY NOT COMPOSER. A PrestaShop module is installed by uploading a ZIP into the
 * back office; there is no `composer install` step on a merchant's shop, so a
 * module that resolves dependencies at install time simply fails on most of them.
 * The adapter kit is therefore VENDORED — copied in, not required — and this maps
 * the two namespaces onto directories without pulling in a dependency of its own.
 *
 * It is registered rather than replacing anything: PrestaShop has its own
 * autoloader and both coexist, each returning quietly for prefixes it does not own.
 */
spl_autoload_register(function ($class) {
    static $prefixes = array(
        'NitroSearch\\AdapterKit\\' => __DIR__ . '/vendor/nitrosearch-contract/src/',
        'NitroSearch\\' => __DIR__ . '/src/',
    );

    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }

        $relative = substr($class, $length);
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;

            return;
        }
    }
});
