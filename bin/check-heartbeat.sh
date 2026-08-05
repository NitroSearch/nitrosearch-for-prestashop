#!/usr/bin/env bash
#
# Refuse to ship a module whose unattended paths cannot keep the shop alive.
#
# WHY THIS EXISTS. Everything the module does without a merchant present lives in
# `Sync\ResyncCheck`: renewing the scoped search key before it expires, noticing
# that the service has asked for a full re-send, and picking up a key after
# out-of-band verification. Nothing calls it on its own — it has to be wired into
# the two paths that run unattended, and there are exactly two: the cron entry
# point and the page-load fallback for shops with no cron.
#
# THE FAILURE MODE IS SILENCE, TWICE OVER, AND BOTH HAPPENED HERE.
#
#   1. A POLL IS NOT A RENEWAL. `ResyncCheck` shipped in 1.0.0 and 1.1.0 fetching
#      a search key only when the shop held NONE. `/v1/status` cannot carry a key,
#      and an EXPIRED key is still a non-empty string — so the gate never fired
#      for the one shop that needed it. Every shop that connected and was then
#      simply used would have gone dark a year later, with the Configure screen
#      still reporting a healthy connection.
#
#   2. THE FALLBACK SKIPPED IDLE SHOPS. `Drain::tick()` returned before scheduling
#      anything when the outbox was empty — and an empty outbox is the steady
#      state of every healthy catalogue, so on shops with no cron the heartbeat
#      never ran at all. The cron path called it unconditionally, which meant the
#      shops that followed the setup instructions were covered and the ones that
#      did not were the ones silently losing their key.
#
# Neither is visible from the back office, neither breaks a test, and neither
# shows up for a year. So they are checked mechanically instead.
#
#   bin/check-heartbeat.sh              # check the working tree
#   bin/check-heartbeat.sh <path>       # check some other checkout
#   bin/check-heartbeat.sh --self-test  # prove the checks still bite
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

FAILED=0
fail() { red "FAIL  $*"; FAILED=1; }

HEARTBEAT_CLASS='src/Sync/ResyncCheck.php'
DRAIN='src/Sync/Drain.php'

# Live (non-commented) occurrences of a pattern in one file. A call that has been
# commented out reads as absent, which is what it is.
live() {
  sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' "$2" 2>/dev/null \
    | grep -cF "$1" || true
}

check_heartbeat() {
  local root="$1"

  if [ ! -f "$root/$HEARTBEAT_CLASS" ]; then
    fail "$HEARTBEAT_CLASS is missing — the module has no unattended heartbeat"
    return
  fi

  # 1. It must RENEW the key, not merely poll. A heartbeat that only ever calls
  #    /v1/status leaves every shop on the key it was issued at onboarding.
  if [ "$(live 'fetchSearchKey' "$root/$HEARTBEAT_CLASS")" -eq 0 ]; then
    fail "$HEARTBEAT_CLASS never calls fetchSearchKey() — it polls but never renews the search key"
  fi

  # 1b. And the renewal must run on its OWN clock. Sharing the poll's stamp makes
  #     it a backfill again: the fetch only ever happens as a side effect of a
  #     poll, so a failing status endpoint suppresses it silently.
  if [ "$(live 'REFRESH_INTERVAL' "$root/$HEARTBEAT_CLASS")" -eq 0 ]; then
    fail "$HEARTBEAT_CLASS has no REFRESH_INTERVAL — the key refresh has no clock of its own"
  fi

  # 2. Both unattended paths must reach it: the cron entry point and the no-cron
  #    page-load fallback, which live in the same file here.
  if [ ! -f "$root/$DRAIN" ]; then
    fail "$DRAIN is missing — cannot tell what runs unattended"
    return
  fi

  if [ "$(live 'ResyncCheck::maybeRun()' "$root/$DRAIN")" -lt 2 ]; then
    fail "$DRAIN calls ResyncCheck::maybeRun() fewer than twice — the cron path and the deferred page-load tick must BOTH reach it"
  fi

  # 3. ⚠ THE FALLBACK MUST NOT BE GATED ON THERE BEING SYNC WORK. This is the
  #    check for defect 2 above, and it is the one that is easiest to undo by
  #    accident: `tick()` deciding on `pendingCount()` alone reads as an obvious
  #    optimisation and silently strands every idle shop that has no cron.
  if [ "$(live 'ResyncCheck::isDue()' "$root/$DRAIN")" -eq 0 ]; then
    fail "$DRAIN never consults ResyncCheck::isDue() — the page-load fallback would skip idle shops, which is every healthy shop"
  fi
}

# ── Self-test ────────────────────────────────────────────────────────────────
#
# Every case below is a state this module has actually been in, or one step from
# it. The verdict is read out of the OUTPUT, not out of `$FAILED`: running a check
# inside `$( … )` puts it in a subshell and the flag it sets is discarded.
self_test() {
  local tmp good_out

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  printf 'self-test\n'

  mkdir -p "$tmp/good/src/Sync"
  cat > "$tmp/good/src/Sync/ResyncCheck.php" <<'GOOD'
<?php
const REFRESH_INTERVAL = 86400;
Client::fetchSearchKey();
GOOD
  cat > "$tmp/good/src/Sync/Drain.php" <<'GOODDRAIN'
<?php
// cron path
ResyncCheck::maybeRun();
// page-load fallback
if (Outbox::pendingCount() <= 0 && !ResyncCheck::isDue()) { return; }
ResyncCheck::maybeRun();
GOODDRAIN

  good_out="$( check_heartbeat "$tmp/good" 2>&1 )" || true
  case "$good_out" in
    *FAIL*) red "  the guard fired on a CORRECT tree:"; printf '%s\n' "$good_out"; exit 1 ;;
  esac
  green "  ok  stays quiet on a correctly wired tree"

  try_evasion() {
    local label="$1" expect="$2" out
    out="$( check_heartbeat "$tmp/bad" 2>&1 )" || true
    case "$out" in
      *"$expect"*) green "  ok  fires on: ${label}" ;;
      *) red "  the guard did NOT fire on: ${label}"; printf '%s\n' "$out"; exit 1 ;;
    esac
  }

  # The state 1.0.0 and 1.1.0 actually shipped in: a backfill, not a renewal.
  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<?php\nif ($key === "") { Client::fetchSearchKey(); }\n' > "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "a heartbeat with no refresh clock of its own" "no REFRESH_INTERVAL"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<?php\nconst REFRESH_INTERVAL = 86400;\nClient::status();\n' > "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "a heartbeat that polls but never renews" "never renews"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<?php\nif (Outbox::pendingCount() <= 0) { return; }\nResyncCheck::maybeRun();\nResyncCheck::maybeRun();\n' \
      > "$tmp/bad/src/Sync/Drain.php"
  try_evasion "the fallback gated on sync work alone — idle shops stranded" "never consults ResyncCheck::isDue()"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<?php\nif (Outbox::pendingCount() <= 0 && !ResyncCheck::isDue()) { return; }\nResyncCheck::maybeRun();\n' \
      > "$tmp/bad/src/Sync/Drain.php"
  try_evasion "only one of the two unattended paths wired" "fewer than twice"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  printf '<?php\n// ResyncCheck::maybeRun();\n// ResyncCheck::maybeRun();\nResyncCheck::isDue();\n' \
      > "$tmp/bad/src/Sync/Drain.php"
  try_evasion "both calls commented out" "fewer than twice"

  rm -rf "$tmp/bad"; cp -R "$tmp/good" "$tmp/bad"
  rm -f "$tmp/bad/src/Sync/ResyncCheck.php"
  try_evasion "no heartbeat at all" "no unattended heartbeat"

  green "self-test passed"
  exit 0
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
fi

if [ -n "${1:-}" ]; then
  ROOT="$(cd "$1" && pwd)"
fi

check_heartbeat "$ROOT"

if [ "$FAILED" -ne 0 ]; then
  red "heartbeat check FAILED"
  exit 1
fi

green "ok    the heartbeat renews the search key on its own clock, from both unattended paths"
