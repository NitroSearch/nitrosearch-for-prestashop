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

/**
 * The anti-forgery token on the Configure screen's forms.
 *
 *   token = hex(hmac_sha256(_COOKIE_KEY_, "nitrosearch-admin-form-v1\n" + id_employee))
 *
 * WHY THE MODULE ISSUES ITS OWN. It used to render
 * Tools::getAdminTokenLite('AdminModules') into the form and compare the posted
 * value against a fresh call on submit. On PrestaShop 8 that call hashes a fixed
 * string, so the two always agree. On PrestaShop 9 it returns a Symfony CSRF
 * token, which is re-masked on EVERY call. The fresh value can therefore never
 * equal the posted one, and every action on the screen (Connect, Save,
 * Disconnect) answered "Your session has expired" on every PrestaShop 9 shop.
 *
 * This keeps PrestaShop 8's model on every version: a value tied to the employee
 * and to a secret only this shop holds. A forged form from another site cannot
 * produce it. The prefix is domain separation, as in VerifyChallenge, so this
 * HMAC can never be mistaken for any other one keyed by the same secret.
 */
final class AdminFormToken
{
    const PREFIX = 'nitrosearch-admin-form-v1';

    /**
     * @param int    $employeeId the signed-in employee
     * @param string $secret     the shop's _COOKIE_KEY_
     *
     * @return string hex-encoded HMAC-SHA256, or '' when either input is missing
     */
    public static function issue($employeeId, $secret)
    {
        $employeeId = (int) $employeeId;
        if ($employeeId <= 0 || (string) $secret === '') {
            return '';
        }

        return hash_hmac('sha256', self::PREFIX . "\n" . $employeeId, (string) $secret);
    }

    /**
     * @param mixed  $provided   the posted value
     * @param int    $employeeId the signed-in employee
     * @param string $secret     the shop's _COOKIE_KEY_
     *
     * @return bool
     */
    public static function verify($provided, $employeeId, $secret)
    {
        $expected = self::issue($employeeId, $secret);

        return $expected !== '' && is_string($provided) && hash_equals($expected, $provided);
    }
}
