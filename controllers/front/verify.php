<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

use NitroSearch\Settings;
use NitroSearch\Support\VerifyChallenge;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Proof-of-control endpoint. NitroSearch fetches this to confirm that this shop
 * controls its own hostname before it will index anything.
 *
 *   GET /index.php?fc=module&module=nitrosearch&controller=verify&nonce=…
 *   → 200 {"proof": "<hex>"}
 *
 * IT IS DELIBERATELY PUBLIC, AND THAT IS SAFE. The service is unauthenticated
 * when it makes this call — it is trying to establish who we are, so it has
 * nothing to authenticate with yet. What makes the endpoint safe is that the
 * answer is an HMAC over this shop's sync secret: a caller who does not hold the
 * secret cannot produce it, so simply reflecting the nonce never passes. And
 * because the proof is domain-separated from the ingest signature, this endpoint
 * can never be used as an oracle to sign an ingest request.
 *
 * REACHED THROUGH THE DISPATCHER FORM, not the rewritten `/module/nitrosearch/verify`.
 * Both work while friendly URLs are on, but that is a per-merchant setting and the
 * service gets one attempt at a path on a shop it has never seen.
 */
class NitroSearchVerifyModuleFrontController extends ModuleFrontControllerCore
{
    /** Skip theme rendering entirely — this returns JSON, not a page. */
    public $ajax = true;

    /** No customer session required, and none is created. */
    public $auth = false;

    /** @var bool this endpoint is machine-to-machine; SSL is the shop's own setting */
    public $ssl = false;

    public function initContent()
    {
        // No parent::initContent(): that begins assembling a themed page, which
        // this endpoint must never emit. A stray byte of HTML around the JSON
        // would fail the content-type check and read as a failed verification.

        $nonce = Tools::getValue('nonce');
        if (!is_string($nonce) || $nonce === '') {
            $this->respond(array('error' => 'missing_nonce'), 400);
        }

        // Bounded and character-checked before it reaches the HMAC. The service
        // sends 64 hex characters; anything else is not ours to sign, and refusing
        // early keeps arbitrary caller-controlled input out of the proof entirely.
        if (!preg_match('/^[a-f0-9]{16,128}$/i', $nonce)) {
            $this->respond(array('error' => 'invalid_nonce'), 400);
        }

        $secret = (string) Settings::get('SYNC_SECRET');
        if ($secret === '') {
            // Not connected yet, so there is no secret to prove control with. 409
            // rather than 500: nothing is broken, the handshake simply has not
            // happened in this order.
            $this->respond(array('error' => 'not_connected'), 409);
        }

        $this->respond(array('proof' => VerifyChallenge::proof($nonce, $secret)), 200);
    }

    /**
     * Emit JSON and stop.
     *
     * THE CONTENT TYPE IS PART OF THE CONTRACT. The service requires
     * `application/json` and treats anything else as a failed proof — deliberately,
     * so that an arbitrary HTML page which happens to contain a `{"proof":…}`
     * string cannot pass verification. A theme or a host that injects markup here
     * would break it, which is why nothing above renders a template.
     *
     * @param array<string, string> $payload
     * @param int                   $status
     */
    private function respond(array $payload, $status)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $status);
            // This answer is per-nonce and must never be served from a cache — a
            // cached proof would be replayed for a different challenge and fail.
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo json_encode($payload);
        exit;
    }
}
