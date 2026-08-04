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
 * HMAC request signing for the NitroSearch ingest API.
 *
 * THIS MUST STAY BYTE-COMPATIBLE WITH THE SERVICE'S VERIFIER. The canonical
 * string and the header names are the wire contract; neither side may change
 * without the other. There is no negotiation and no version fallback — a
 * mismatch is a 401, not a downgrade.
 *
 * Canonical string (newline-joined, in this order):
 *
 *   v1 \n timestamp \n jti \n key_id \n METHOD \n path \n sha256(body) \n site_url \n install_id
 *
 * Two properties are worth understanding rather than copying:
 *
 * - `jti` is a fresh 128-bit nonce per request, accepted ONCE by the service.
 *   With the timestamp window that is what stops a captured request being
 *   replayed. Reusing a jti — caching the headers, retrying with the same ones —
 *   fails, by design.
 * - The signature covers `sha256(body)` and the PATH, but NOT the query string.
 *   Anything that must be signed therefore rides the body, which is why the
 *   resync acknowledgement is a POST rather than a parameter on the poll.
 */
final class Hmac
{
    const VERSION = 'v1';

    /**
     * @param string $body raw request body; the empty string for GET
     */
    public static function canonical(
        $timestamp,
        $jti,
        $keyId,
        $method,
        $path,
        $body,
        $siteUrl,
        $installId
    ) {
        return implode("\n", array(
            self::VERSION,
            (string) $timestamp,
            $jti,
            $keyId,
            // Deliberately the PHP builtin and NOT PrestaShop's Tools::strtoupper.
            // This file is namespaced, so an unqualified `Tools::` would resolve to
            // NitroSearch\Support\Tools and fatal. It is also a signing input: the
            // canonical string must be produced identically by every module on every
            // host, so it may not depend on a framework helper's locale handling.
            strtoupper($method),
            $path,
            hash('sha256', $body),
            $siteUrl,
            $installId,
        ));
    }

    public static function sign($secret, $canonical)
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * A fresh 128-bit per-request nonce (hex).
     *
     * `random_bytes` is required, never `rand()`/`uniqid()`: a predictable jti
     * lets an observer pre-compute the one value the replay guard depends on.
     * PHP 7+ always has it, so there is no fallback to be tempted by.
     *
     * @return string
     */
    public static function newJti()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build the signed headers for one request. Generates a fresh jti each call —
     * so call it per request, never once per batch of retries.
     *
     * @return array<string, string>
     */
    public static function headers($keyId, $secret, $method, $path, $body, $siteUrl, $installId)
    {
        $timestamp = time();
        $jti = self::newJti();
        $canonical = self::canonical($timestamp, $jti, $keyId, $method, $path, $body, $siteUrl, $installId);

        return array(
            'X-NS-Key' => $keyId,
            'X-NS-Timestamp' => (string) $timestamp,
            'X-NS-Jti' => $jti,
            'X-NS-Signature' => self::sign($secret, $canonical),
            'X-NS-Site-Url' => $siteUrl,
            'X-NS-Install-Id' => $installId,
        );
    }
}
