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
use NitroSearch\Sync\Drain;
use NitroSearch\Sync\FullSync;
use NitroSearch\Sync\Outbox;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The cron entry point — the primary way the outbox drains.
 *
 *   /index.php?fc=module&module=nitrosearch&controller=cron&token=…
 *
 * A merchant points their host's cron at this every few minutes. The module still
 * syncs without it, through the bounded page-load fallback, but cron is the path
 * that keeps a large catalogue moving at a sensible rate.
 *
 * TOKEN-GATED, WITH A CONSTANT-TIME COMPARISON. The token is not a secret worth
 * much on its own — the worst an attacker can do with it is make us sync — but an
 * unauthenticated endpoint that performs unbounded work is a free denial-of-
 * service against the merchant's own server, so it is gated anyway. `hash_equals`
 * rather than `===` because a plain comparison leaks the token's prefix through
 * timing, and there is no reason to hand that away.
 */
class NitroSearchCronModuleFrontController extends ModuleFrontControllerCore
{
    public $ajax = true;

    public $auth = false;

    public $ssl = false;

    public function initContent()
    {
        $provided = (string) Tools::getValue('token');
        $expected = (string) Settings::get('DRAIN_TOKEN');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            $this->respond(array('error' => 'forbidden'), 403);
        }

        if (!Settings::isConnected()) {
            $this->respond(array('error' => 'not_connected'), 409);
        }

        // Keep a full walk moving first: enumeration feeds the queue the drain
        // then empties, so doing it in this order means one invocation makes
        // progress on both rather than draining an empty queue and stopping.
        FullSync::resumeIfStalled();

        $result = Drain::run();

        $this->respond(array(
            'ok' => true,
            'batches' => $result['batches'],
            'items' => $result['items'],
            'stopped' => $result['stopped'],
            'pending' => Outbox::pendingCount(),
            'full_sync_active' => FullSync::isActive(),
        ), 200);
    }

    /**
     * @param array<string, mixed> $payload
     * @param int                  $status
     */
    private function respond(array $payload, $status)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, $status);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo json_encode($payload);
        exit;
    }
}
