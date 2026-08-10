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
 * ORDER REPORTING — WHICH ANSWERS DESTROY AN ORDER AND WHICH ONE COMES BACK.
 *
 * This is revenue attribution: the number a merchant reads to decide whether
 * search is worth paying for. Until 2026-08-10 the send side classified EVERY
 * 4xx as final and deleted the queued row, which threw the order away for good
 * on three answers that are temporary states rather than verdicts:
 *
 *   429 the shop is sending faster than the per-shop rate limit allows, which
 *       happens exactly during a flash sale, so the busiest hour of the year
 *       reported the least revenue;
 *   409 the shop is not verified YET, an ordinary few minutes of onboarding;
 *   423 the account is suspended, a state shops come back from.
 *
 * Each cost one order permanently, and nothing anywhere said so.
 *
 * THE OPPOSITE MISTAKE IS WORSE, which is why half of this file is about a
 * timestamp. The service deduplicates a report on (shop, order reference,
 * occurred_at), so a retry is free ONLY while it re-sends the same bytes. A
 * timestamp derived at send time, or a report sent so late the service re-dates
 * it, becomes a SECOND sale for one order and overstates a merchant's revenue.
 *
 * Both halves are exercised against the shipping classes; neither needs a shop,
 * a database or the network.
 */

require_once dirname(dirname(__DIR__)).'/src/Api/Client.php';
require_once dirname(dirname(__DIR__)).'/src/Sync/OrderAttribution.php';

use NitroSearch\Api\Client;
use NitroSearch\Sync\OrderAttribution;

/**
 * Reach a private static. The classification is not part of the module's public
 * surface and should not become part of it just to be testable.
 *
 * @param string $class
 * @param string $method
 * @param array<int, mixed> $args
 *
 * @return mixed
 */
function ns_call_private($class, $method, array $args)
{
    $reflected = new ReflectionMethod($class, $method);
    if (PHP_VERSION_ID < 80100) {
        // Needed up to 8.0, a no-op from 8.1, and DEPRECATED as of 8.5 — calling
        // it unconditionally printed a notice per assertion. The module already
        // carries one scar from exactly this pattern (see the cURL handle in
        // Api\Client), so do not put a second one in the test suite.
        $reflected->setAccessible(true);
    }

    return $reflected->invokeArgs(null, $args);
}

return array(
    'a temporary refusal is retried, not discarded' => function () {
        // The three that used to be dropped, plus the rest of the retryable set.
        // 401 is retryable HERE and would not be on a caller that cached headers:
        // the signature and its nonce are rebuilt inside every attempt, so the
        // next request is genuinely different rather than a replay of the one
        // that was just refused.
        foreach (array(401, 408, 409, 423, 425, 429) as $status) {
            ns_true(
                'HTTP '.$status.' is retryable',
                ns_call_private('NitroSearch\Api\Client', 'isOrderRetryable', array($status))
            );
        }
    },

    'a service fault or a lost request is retried' => function () {
        foreach (array(500, 502, 503, 504, 599) as $status) {
            ns_true('HTTP '.$status.' is retryable', ns_call_private('NitroSearch\Api\Client', 'isOrderRetryable', array($status)));
        }

        // Status 0 is a transport failure — timeout, DNS, TLS, refused
        // connection. NOTHING is known about whether the order was recorded, and
        // asking again is safe only because the payload is re-sent unchanged.
        ns_true('a transport failure is retryable', ns_call_private('NitroSearch\Api\Client', 'isOrderRetryable', array(0)));
    },

    'a verdict is accepted rather than argued with' => function () {
        // The self-negative. If these were retryable too, the fix would just be
        // "retry everything", and a shop with a malformed payload or no
        // entitlement would spend its own heartbeat forever being told the same
        // thing. 422 in particular must stay final: a payload that is wrong now
        // is just as wrong in an hour.
        foreach (array(200, 201, 202, 204, 400, 403, 404, 410, 422) as $status) {
            ns_false(
                'HTTP '.$status.' is not retryable',
                ns_call_private('NitroSearch\Api\Client', 'isOrderRetryable', array($status))
            );
        }
    },

    'the retry set is exactly the temporary answers' => function () {
        // Pinned as a list rather than a range, because a range is the mistake
        // being corrected: `>= 400 && < 500` is what discarded the orders.
        ns_is('retry codes', array(401, 408, 409, 423, 425, 429), Client::ORDER_RETRY_CODES);

        foreach (array(400, 403, 404, 410, 422) as $final) {
            ns_false('HTTP '.$final.' is absent from the list', in_array($final, Client::ORDER_RETRY_CODES, true));
        }
    },

    'done and retry are exact opposites' => function () {
        // The caller reads `retry`; anything that reports both, or neither,
        // would either drop an order or queue one forever.
        foreach (array(array(true, 202), array(false, 429)) as $pair) {
            $outcome = ns_call_private('NitroSearch\Api\Client', 'orderOutcome', array($pair[0], $pair[1], ''));

            ns_is('done', $pair[0], $outcome['done']);
            ns_is('retry', !$pair[0], $outcome['retry']);
            ns_is('status is carried', $pair[1], $outcome['status']);
        }
    },

    'a frozen timestamp survives a timezone change; a derived one does not' => function () {
        // The frozen value is a literal because it is returned verbatim and can
        // never drift. The legacy row's stored DATETIME is RELATIVE, because the
        // derivation it feeds is now window-checked — pinning a literal there
        // would quietly turn this case red a week after it was written, which is
        // its own kind of silent failure.
        $row = array(
            'occurred_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            'occurred_at_wire' => '2026-08-10T09:15:00+00:00',
        );
        $legacy = array('occurred_at' => gmdate('Y-m-d H:i:s', time() - 3600));

        $was = date_default_timezone_get();

        date_default_timezone_set('UTC');
        $frozenA = ns_call_private('NitroSearch\Sync\OrderAttribution', 'wireTimestamp', array($row));
        $derivedA = ns_call_private('NitroSearch\Sync\OrderAttribution', 'wireTimestamp', array($legacy));

        // A merchant changing their shop's locale settings between two attempts
        // of one report is an ordinary thing to do.
        date_default_timezone_set('Asia/Tokyo');
        $frozenB = ns_call_private('NitroSearch\Sync\OrderAttribution', 'wireTimestamp', array($row));
        $derivedB = ns_call_private('NitroSearch\Sync\OrderAttribution', 'wireTimestamp', array($legacy));

        date_default_timezone_set($was);

        ns_is('the frozen value is sent verbatim', '2026-08-10T09:15:00+00:00', $frozenA);
        ns_is('and is byte-identical after the timezone moves', $frozenA, $frozenB);

        // The characterization, not an endorsement: this is what every release
        // before 2026-08-10 put on the wire, and why the value is now frozen at
        // queue time. Rows written by those releases keep this derivation on
        // purpose — reformatting them would change the deduplication key of a
        // report that may already have been accepted.
        ns_true('a derived value moves with the timezone', $derivedA !== $derivedB);

        // A nonsense stored date must produce NOTHING rather than a date the
        // service would move forward — a moved timestamp is a deduplication key
        // we never sent, so a retry of an already-accepted order becomes a second
        // sale. Client::reportOrder() refuses an empty one outright.
        //
        // `0000-00-00` is the case that made this a window check rather than a
        // parse check: strtotime() does not return false for it, it returns a
        // perfectly usable timestamp in the year -1.
        $nonsense = array(
            'a zero date' => '0000-00-00 00:00:00',
            'an empty column' => '',
            'a clock years fast' => gmdate('Y-m-d H:i:s', time() + (400 * 86400)),
            'a date past the queue bound' => gmdate('Y-m-d H:i:s', time() - (30 * 86400)),
        );
        foreach ($nonsense as $label => $stored) {
            ns_is(
                $label.' yields no timestamp at all',
                '',
                ns_call_private('NitroSearch\\Sync\\OrderAttribution', 'wireTimestamp', array(array('occurred_at' => $stored)))
            );
        }
    },

    'a report is never sent late enough to be re-dated' => function () {
        // The service will not record a conversion at an `occurred_at` older
        // than about eight days; it moves it forward instead. A moved timestamp
        // is a different deduplication key, so a late retry of an order that was
        // already accepted would be counted TWICE. The queue's own bound has to
        // sit strictly inside that window — it was fourteen days, which left six
        // days of exposure.
        ns_true('the queue bound is inside the service window', OrderAttribution::REPORT_TTL_DAYS <= 7);

        $day = 86400;
        $old = array('created_at' => date('Y-m-d H:i:s', time() - (8 * $day)));
        $fresh = array('created_at' => date('Y-m-d H:i:s', time() - (6 * $day)));

        ns_true('an eight-day-old report is refused', ns_call_private('NitroSearch\Sync\OrderAttribution', 'tooOldToSend', array($old)));
        ns_false('a six-day-old report still goes', ns_call_private('NitroSearch\Sync\OrderAttribution', 'tooOldToSend', array($fresh)));

        // An unparseable date must not delete revenue data.
        ns_false(
            'an unreadable date is not treated as old',
            ns_call_private('NitroSearch\Sync\OrderAttribution', 'tooOldToSend', array(array('created_at' => 'not a date')))
        );
    },

    'the retry ladder finishes well inside that bound' => function () {
        // Otherwise a report could still be waiting for its last attempt at the
        // moment age forces it out, and the two bounds would be fighting.
        ns_is('one delay per retry', OrderAttribution::MAX_ATTEMPTS - 1, count(OrderAttribution::BACKOFF));

        $ladder = array_sum(OrderAttribution::BACKOFF);
        ns_true('the ladder is bounded', $ladder < (OrderAttribution::REPORT_TTL_DAYS * 86400));

        // And the delays widen rather than hammering a service that has just
        // said "not now".
        $previous = 0;
        foreach (OrderAttribution::BACKOFF as $delay) {
            ns_true('delay '.$delay.' widens', $delay > $previous);
            $previous = $delay;
        }
    },
);
