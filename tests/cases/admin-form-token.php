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
 * THE CONFIGURE SCREEN'S FORM TOKEN — the reason no PrestaShop 9 shop could connect.
 *
 * The screen used to compare the posted token with a fresh
 * Tools::getAdminTokenLite() call. PrestaShop 9 returns a re-masked Symfony CSRF
 * token from that call, different every time, so every action failed as
 * "Your session has expired". Nothing here could see it: this runner has no
 * PrestaShop, and the token only misbehaves inside a real 9.x back office.
 *
 * So two kinds of test. The token itself is pinned as a literal (a value that
 * changes between render and submit is exactly the failure). And the screen is
 * checked STRUCTURALLY: it must issue and verify through AdminFormToken, and must
 * not reach for the core token, whose behaviour depends on the version.
 */

require_once dirname(dirname(__DIR__)).'/src/Support/AdminFormToken.php';

use NitroSearch\Support\AdminFormToken;

return array(
    'the token is pinned' => function () {
        ns_is(
            'token',
            hash_hmac('sha256', "nitrosearch-admin-form-v1\n".'7', 'cookiekey'),
            AdminFormToken::issue(7, 'cookiekey')
        );
    },

    'the same employee gets the same token on render and on submit' => function () {
        ns_is('stable', AdminFormToken::issue(3, 'k'), AdminFormToken::issue(3, 'k'));
        ns_true('verifies', AdminFormToken::verify(AdminFormToken::issue(3, 'k'), 3, 'k'));
    },

    'another employee, or another shop, does not' => function () {
        ns_false('other employee', AdminFormToken::verify(AdminFormToken::issue(3, 'k'), 4, 'k'));
        ns_false('other secret', AdminFormToken::verify(AdminFormToken::issue(3, 'k'), 3, 'other'));
    },

    'missing or malformed input is refused, never waved through' => function () {
        ns_is('no employee issues nothing', '', AdminFormToken::issue(0, 'k'));
        ns_is('no secret issues nothing', '', AdminFormToken::issue(3, ''));
        ns_false('empty posted value', AdminFormToken::verify('', 3, 'k'));
        ns_false('array posted value', AdminFormToken::verify(array(AdminFormToken::issue(3, 'k')), 3, 'k'));
        ns_false('no employee', AdminFormToken::verify(AdminFormToken::issue(0, 'k'), 0, 'k'));
        ns_false('wrong value', AdminFormToken::verify(str_repeat('0', 64), 3, 'k'));
    },

    'the Configure screen issues and verifies through it, not the core token' => function () {
        $source = file_get_contents(dirname(dirname(__DIR__)).'/src/Admin/ConfigurePage.php');
        // Comments out first: the class explains the old call by name, and a
        // guard that matches its own explanation passes over a revert.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        ns_false('no core admin token', (bool) preg_match('/getAdminToken/', $code));
        ns_true('issues it', strpos($code, 'AdminFormToken::issue(') !== false);
        ns_true('verifies it', strpos($code, 'AdminFormToken::verify(') !== false);
    },
);
