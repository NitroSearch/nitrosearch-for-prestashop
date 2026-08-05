<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Sync;

if (!defined('_PS_VERSION_')) {
    exit;
}

use NitroSearch\Api\Client;
use NitroSearch\Settings;

/**
 * Periodic status poll — and the handler for a re-sync the service asks for.
 *
 * WHY THIS EXISTS. Sending the catalogue is fire-and-forget: the service accepts
 * a batch, answers straight away, and this module then clears those rows from its
 * outbox. If something in that batch turns out to be unusable once the service
 * opens it — an item it cannot read, or one that would push the shop past its
 * plan — the item is quietly missing from search and NOTHING ON THIS SIDE KNOWS
 * TO SEND IT AGAIN. The rows are gone; we believe we are in sync; we are not.
 *
 * The service can now say so, and this is what listens. The status response
 * carries a `resync` block only while a request is outstanding; on seeing one we
 * start a full walk and confirm it, and the block disappears.
 *
 * It rides the drain heartbeat rather than having its own schedule, which on a
 * platform with no job runner is the difference between one mechanism to keep
 * alive and two.
 */
final class ResyncCheck
{
    /**
     * Frequent enough that a request is picked up while someone is still watching
     * for it; rare enough to be invisible — twelve small reads an hour.
     */
    const INTERVAL = 300;

    /**
     * Seconds between search-key REFRESHES — a different clock, and a different job.
     *
     * ⚠ THE POLL CANNOT RENEW THE KEY, and for a long time this class behaved as
     * though it could. `/v1/status` does not carry a key; only `/v1/search-key`
     * does. The fetch below was gated on the shop holding NO key, which is a
     * backfill, not a renewal — and an EXPIRED key is still a non-empty string, so
     * the gate never fired for the one shop that needed it. A shop that connected
     * and was then simply used would find its storefront search returning nothing a
     * year later, with the Configure screen still reporting a healthy connection.
     *
     * A day, against a key lifetime measured in months, so a shop can miss hundreds
     * of consecutive refreshes and still be well inside the margin.
     */
    const REFRESH_INTERVAL = 86400;

    public static function maybeRun()
    {
        if (!Settings::isConnected()) {
            return;
        }

        // The key refresh is decided FIRST and on its own clock, so that a status
        // endpoint which is slow, failing or simply not yet due cannot suppress the
        // one job whose absence silently kills storefront search.
        if (self::refreshDue()) {
            self::refreshSearchKey();
        }

        if (time() - (int) Settings::get('STATUS_CHECKED_AT', 0) < self::INTERVAL) {
            return;
        }

        try {
            $status = Client::status();
        } catch (\Exception $e) {
            // A status fault must never take the drain down with it — the sync is
            // the job, this is housekeeping.
            Settings::update(array('STATUS_CHECKED_AT' => time()));

            return;
        }

        // Stamped on every completed attempt, success or not, so an unreachable
        // service gets one polite retry per interval rather than one per heartbeat.
        Settings::update(array('STATUS_CHECKED_AT' => time()));

        if (empty($status['ok'])) {
            return;
        }

        // If the service says we are verified but we have never picked up a search
        // key, fetch it. This is how a shop verified out of band — by the service's
        // own loopback, not by a call we made — gets a working storefront widget
        // without the merchant having to press anything.
        if (!empty($status['verified']) && (string) Settings::get('SCOPED_SEARCH_KEY') === '') {
            Client::fetchSearchKey();
        }

        $resync = isset($status['resync']) ? $status['resync'] : null;
        if (!is_array($resync) || empty($resync['required'])) {
            return;
        }

        $token = isset($resync['token']) ? (string) $resync['token'] : '';
        if ($token === '') {
            return;
        }

        self::handle($token);
    }

    /**
     * Whether the daily search-key refresh is due. One cached settings read.
     *
     * @return bool
     */
    public static function refreshDue()
    {
        return (time() - (int) Settings::get('CONFIG_REFRESHED_AT', 0)) >= self::REFRESH_INTERVAL;
    }

    /**
     * Whether anything at all is due — the poll or the refresh.
     *
     * EXISTS FOR THE CALLER'S BENEFIT. `Drain::tick()` has to decide whether to
     * register a deferred run BEFORE doing any work, and asking must not itself
     * cost a request.
     *
     * @return bool
     */
    public static function isDue()
    {
        if (!Settings::isConnected()) {
            return false;
        }

        if (time() - (int) Settings::get('STATUS_CHECKED_AT', 0) >= self::INTERVAL) {
            return true;
        }

        return self::refreshDue();
    }

    /**
     * Re-fetch the scoped search key, whether or not one is already held.
     *
     * THIS IS THE JOB THE POLL CANNOT DO. The key the widget searches with carries
     * a baked-in expiry and `/v1/status` never carries a replacement, so the only
     * way to get a fresh one is to ask `/v1/search-key` on a clock.
     *
     * ⚠ THE CLOCK IS STAMPED BEFORE THE ELIGIBILITY TEST, and the ordering is
     * load-bearing. Stamping only on the eligible path leaves a not-yet-verified
     * shop permanently "refresh due", which makes `isDue()` permanently true and has
     * the page-load fallback registering a deferred run on every tick forever for a
     * shop with nothing to do. Stamping unconditionally costs an ineligible shop
     * nothing: the five-minute poll below still backfills a missing key the moment
     * verification lands, so nothing waits a day for something it needs sooner.
     *
     * FAILURE IS SAFE. `Client::fetchSearchKey()` refuses to overwrite a stored key
     * with a response that did not decode to the expected shape, so a bad answer
     * leaves the working key in place and the next day's attempt is soon enough.
     */
    private static function refreshSearchKey()
    {
        Settings::update(array('CONFIG_REFRESHED_AT' => time()));

        // Belt and braces: the stored `verified` flag can lag reality on a shop
        // verified out of band, so a shop that HOLDS a key is refresh-eligible
        // whatever the flag says. The service answers 409 harmlessly otherwise.
        if (!Settings::get('VERIFIED') && (string) Settings::get('SCOPED_SEARCH_KEY') === '') {
            return;
        }

        try {
            Client::fetchSearchKey();
        } catch (\Exception $e) {
            // Housekeeping must never take the drain down with it.
        }
    }

    /**
     * Start the requested walk, then confirm it.
     *
     * THE ORDER IS DELIBERATE. The token is recorded as acted on BEFORE the
     * confirmation is sent, so a confirmation that fails to arrive costs one
     * retry rather than a second full walk: the request stays outstanding, the
     * next check sees the same token, skips the walk it has already started, and
     * simply tries the confirmation again.
     *
     * Doing it the other way round — confirm first, record after — would restart
     * the entire catalogue every five minutes for as long as the confirmation kept
     * failing, which on a large shop is exactly the runaway load this module is
     * careful to avoid everywhere else.
     *
     * @param string $token
     */
    private static function handle($token)
    {
        if ((string) Settings::get('RESYNC_TOKEN_DONE', '') !== $token) {
            FullSync::start();
            Settings::update(array('RESYNC_TOKEN_DONE' => $token));
        }

        Client::acknowledgeResync($token);
    }
}
