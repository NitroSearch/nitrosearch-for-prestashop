#!/usr/bin/env bash
#
# Refuse to ship a config blob that can break out of its own <script> block.
#
# WHY THIS EXISTS. The storefront widget is configured by a JSON object written
# straight into the page:
#
#     <script>window.NitroSearchConfig={…};</script>
#
# A `</script>` anywhere inside that JSON closes the block early and everything
# after it becomes live markup. A merchant-supplied CSS selector reaches the
# object as free text, so "nothing should contain one" is a hope, not a property.
#
# WHAT WENT WRONG, AND WHY A GREP IS THE RIGHT SHAPE OF ANSWER. The module used
# to do this by hand:
#
#     $json = str_replace('<', '<', $json);
#
# The needle and the replacement are the same byte. It compiled, it ran, it
# escaped nothing, and it sat underneath a comment explaining the attack it was
# supposed to prevent. It shipped in 1.0.0 and 1.1.0. Nobody reading the line
# could see it, because there is nothing to see — the bug is that two strings
# are equal.
#
# So this checks two things, and the second one is the general case:
#
#   1. Every `json_encode` in a file that emits `<script>` carries JSON_HEX_TAG.
#   2. No `str_replace` anywhere has a needle identical to its replacement.
#
# THE FILE LIST IS DERIVED, NOT ENUMERATED. Check 1 walks every PHP file that
# contains `<script>`, so a new file that starts emitting one is covered the day
# it is added rather than the day someone remembers to add it here.
#
#   bin/check-script-escaping.sh              # check the working tree
#   bin/check-script-escaping.sh <path>       # check some other checkout
#   bin/check-script-escaping.sh --self-test  # prove the checks still bite
#
# The path argument is how this gets pointed at a checkout of the tag being
# released while running from the current branch, so the newest guard applies to
# whatever is being shipped rather than being frozen at tag time.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }

FAILED=0
fail() { red "FAIL  $*"; FAILED=1; }

# Source files that ship. `dist/` is build output and `.git/` is not code.
#
# ⚠ `vendor/` IS SCANNED, and the earlier version's exclusion of it was simply
# wrong. It is not third-party: `vendor/nitrosearch-contract/` is first-party,
# git-tracked, hand-maintained source, and both builds copy it into the archive a
# merchant installs. Skipping it meant the guard did not look at code it ships.
sources() {
  find "$1" -name '*.php' \
    -not -path '*/dist/*' \
    -not -path '*/.git/*' \
    | sort
}

# A file's code with comments stripped — `//`, `#` and docblock `*` lines.
#
# Needed in BOTH directions. Deciding "does this file emit a <script>?" from raw
# text made merely WRITING the word `<script` in a comment pull an unrelated file
# into the scan (`Api/Client.php` explaining an escaping rule was enough), and
# then failing it for its perfectly correct HTTP-body `json_encode`.
code_of() {
  sed -e 's://.*::' -e 's:#.*::' -e 's:^[[:space:]]*\*.*::' "$1" 2>/dev/null
}

# ── 1. Inline JSON must be tag-escaped by the engine ─────────────────────────
check_hex_tag() {
  local root="$1" file call
  local found=0

  while IFS= read -r file; do
    # Only files that actually write a <script> into a page. Everything else uses
    # json_encode for HTTP bodies, where escaping tags would be pointless.
    code_of "$file" | grep -q '<script' || continue

    # ⚠ THE UNIT IS THE CALL, NOT THE LINE. Matching a single line failed a
    # perfectly correct call the moment it was wrapped across lines — the flag
    # sat two lines below and was never seen. Continuations are joined so that
    # reformatting cannot turn a passing tree into a failing one.
    while IFS= read -r call; do
      found=$((found + 1))
      case "$call" in
        *JSON_HEX_TAG*) ;;
        *)
          fail "${file#"$root"/}: json_encode reaching a <script> block without JSON_HEX_TAG"
          printf '        %s\n' "$(printf '%s' "$call" | cut -c1-100)"
          ;;
      esac
    done < <(code_of "$file" | tr '\n' ' ' | grep -o 'json_encode([^;]*' || true)
  done < <(sources "$root")

  # A check that matches nothing passes vacuously. If the widget stops emitting
  # its config through json_encode, this guard has quietly stopped guarding.
  if [ "$found" -eq 0 ]; then
    fail "no json_encode found in any <script>-emitting file — this guard is no longer watching anything"
  fi
}

# ── 2. No str_replace that replaces a string with itself ─────────────────────
#
# The general form of the bug above. Matched on the two literals being equal
# rather than on any particular character, so the next one is caught whatever it
# is escaping.
check_self_cancelling_replace() {
  local root="$1" file line needle repl

  while IFS= read -r file; do
    while IFS= read -r line; do
      # Pull the first two single-quoted literals out of the call.
      needle="$(printf '%s' "$line" | sed -n "s/.*str_replace(\('[^']*'\)[[:space:]]*,[[:space:]]*\('[^']*'\).*/\1/p")"
      repl="$(printf '%s' "$line"   | sed -n "s/.*str_replace(\('[^']*'\)[[:space:]]*,[[:space:]]*\('[^']*'\).*/\2/p")"

      [ -n "$needle" ] || continue

      if [ "$needle" = "$repl" ]; then
        fail "${file#"$root"/}: str_replace replaces ${needle} with itself — it does nothing"
        printf '        %s\n' "$(printf '%s' "$line" | sed 's/^[[:space:]]*//')"
      fi
    done < <(grep -n 'str_replace(' "$file" || true)
  done < <(sources "$root")
}

# ── Self-test ────────────────────────────────────────────────────────────────
#
# TWO DIRECTIONS, NOT ONE. A guard that fires on the bad case might fire on
# everything; a guard that passes the good case might pass everything. Only both
# together say it discriminates.
#
# ⚠ THE VERDICT IS READ OUT OF THE OUTPUT, NOT OUT OF `$FAILED`. Running a check
# inside `$( … )` puts it in a subshell, so the flag it sets is discarded when
# that subshell exits and the parent always reads 0 — the first version of this
# self-test reported "did not fire" against a tree with both defects in it.
self_test() {
  local tmp bad_out good_out

  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' RETURN

  # The bad tree: both defects, written the way they actually shipped.
  mkdir -p "$tmp/bad"
  cat > "$tmp/bad/widget.php" <<'BADPHP'
<?php
$json = json_encode($config, JSON_UNESCAPED_SLASHES);
$json = str_replace('<', '<', $json);
return '<script>window.NitroSearchConfig=' . $json . ';</script>';
BADPHP

  # The good tree: the same file, fixed — plus the two shapes that made an earlier
  # version of this guard abort the build on CORRECT code. Both are in the GOOD
  # tree on purpose: a guard that cries wolf gets switched off.
  mkdir -p "$tmp/good"
  cat > "$tmp/good/widget.php" <<'GOODPHP'
<?php
$json = json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
$json = str_replace('<', '&lt;', $json);
return '<script>window.NitroSearchConfig=' . $json . ';</script>';
GOODPHP

  # (a) The same correct call, wrapped across lines. The flag is nowhere near the
  #     `json_encode(` token.
  cat > "$tmp/good/wrapped.php" <<'WRAPPED'
<?php
$json = json_encode(
    $config,
    JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
);
return '<script>window.NitroSearchConfig=' . $json . ';</script>';
WRAPPED

  # (b) A file that only MENTIONS <script> in a comment, whose json_encode builds
  #     an HTTP body where tag-escaping would be meaningless.
  cat > "$tmp/good/client.php" <<'CLIENT'
<?php
// A `</script>` in an inline <script> block would close it early. Not relevant
// here: this body goes over the wire, not into a page.
return $this->post('/v1/resync/ack', json_encode(array('token' => $token)));
CLIENT

  printf 'self-test\n'

  bad_out="$( { check_hex_tag "$tmp/bad"; check_self_cancelling_replace "$tmp/bad"; } 2>&1 )" || true
  case "$bad_out" in
    *JSON_HEX_TAG*) : ;;
    *) red "  the guard did NOT fire on a missing JSON_HEX_TAG"; exit 1 ;;
  esac
  case "$bad_out" in
    *"with itself"*) : ;;
    *) red "  the guard did NOT fire on a self-cancelling str_replace"; exit 1 ;;
  esac
  green "  ok  fires on the bad tree (both checks)"

  good_out="$( { check_hex_tag "$tmp/good"; check_self_cancelling_replace "$tmp/good"; } 2>&1 )" || true
  case "$good_out" in
    *FAIL*)
      red "  the guard fired on a CORRECT tree:"
      printf '%s\n' "$good_out"
      exit 1
      ;;
  esac
  green "  ok  stays quiet on the good tree"

  rm -rf "$tmp"
  green "self-test passed"
  exit 0
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
fi

if [ -n "${1:-}" ]; then
  ROOT="$(cd "$1" && pwd)"
fi

check_hex_tag "$ROOT"
check_self_cancelling_replace "$ROOT"

if [ "$FAILED" -ne 0 ]; then
  red "inline-script escaping check FAILED"
  exit 1
fi

green "ok    inline JSON is tag-escaped by the engine, and no str_replace is a no-op"
