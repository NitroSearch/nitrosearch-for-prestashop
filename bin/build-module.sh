#!/usr/bin/env bash
#
# Build the installable module ZIP.
#
# THE ZIP LAYOUT IS THE PRODUCT HERE, not a detail. PrestaShop installs a module
# by unpacking the archive into modules/ and then looking for a directory whose
# name matches the module's technical name, containing a PHP file of that same
# name, defining a class of that same name. Get any one of the three wrong and
# the upload is accepted and the install silently does nothing useful — which is
# precisely the class of defect a bind-mounted working copy cannot show you,
# because a bind mount has already put the files where they belong.
#
# So every one of those invariants is asserted below rather than assumed.
#
#   ./bin/build-module.sh            -> dist/nitrosearch-<version>.zip
#
set -euo pipefail

MODULE="nitrosearch"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
die()   { red "FAIL  $*"; exit 1; }

# ── Version: one number, asserted in both places that carry it ───────────────
#
# The main file and config.xml both declare a version, and PrestaShop reads them
# at different moments — config.xml on the module list, the class property after
# instantiation. When they disagree the back office shows one number and the
# upgrade machinery uses the other, and nobody notices until an upgrade does not
# run.
version_php="$(grep -oE "this->version = '[^']+'" "${MODULE}.php" | head -1 | sed "s/.*'\(.*\)'/\1/")"
version_xml="$(grep -oE '<version><!\[CDATA\[[^]]+' config.xml | head -1 | sed 's/.*\[//')"

[ -n "$version_php" ] || die "no \$this->version in ${MODULE}.php"
[ "$version_php" = "$version_xml" ] \
  || die "version mismatch: ${MODULE}.php says ${version_php}, config.xml says ${version_xml}"

VERSION="$version_php"

# ── The three names that must agree ──────────────────────────────────────────
grep -qE "this->name = '${MODULE}'" "${MODULE}.php" \
  || die "\$this->name is not '${MODULE}' — the install will not find the module"

# PHP class names are case-insensitive, so the class may be NitroSearch while the
# technical name is nitrosearch; what it may NOT be is a different word.
grep -qiE "^class ${MODULE} extends Module" "${MODULE}.php" \
  || die "no 'class ${MODULE} extends Module' in ${MODULE}.php"

# ── Lint everything that ships, before it ships ──────────────────────────────
if command -v php >/dev/null 2>&1; then
  while IFS= read -r file; do
    php -l "$file" >/dev/null 2>&1 || die "PHP syntax error in $file"
  done < <(find . -name '*.php' -not -path './.git/*' -not -path './dist/*')
  green "ok    every PHP file parses"
else
  printf '\033[33mwarn\033[0m  php not on PATH — skipping the lint pass\n'
fi

# ── The config blob must not be able to break out of its own <script> ────────
#
# A no-op escape shipped in 1.0.0 and 1.1.0 — `str_replace('<', '<', …)`, which
# reads exactly like a working one. Unlike the lint above this has no `command
# -v` fallback: a build host without the checker does not get to skip it, because
# skipping it is how the defect shipped in the first place.
# THE LIST IS DERIVED, NOT WRITTEN DOWN. It used to name two guards, and a third
# added beside them would have sat in bin/ running on nobody's machine while the
# build printed its guard section and passed.
_guards_run=0
for guard in "$ROOT"/bin/check-*.sh; do
  [ -f "$guard" ] || continue
  _guards_run=$((_guards_run + 1))
  "$guard" --self-test >/dev/null \
    || die "$(basename "$guard") failed its own self-test — fix the guard before trusting the build"
  "$guard" \
    || die "$(basename "$guard") refused this tree (see above)"
done

# A loop over nothing passes. If the glob stops matching, stop rather than
# quietly package an ungated tree.
[ "$_guards_run" -ge 2 ] \
  || die "only ${_guards_run} guard(s) ran — bin/check-*.sh matched less than expected, so this build is not gated"

# ── The test suite, before anything is packaged ──────────────────────────────
#
# The conformance fixtures sat in tests/ unrun for the module's whole life
# because there was nothing to run them with. Now there is, so the build runs
# them: a module that cannot reproduce the shared HMAC vector cannot talk to the
# service at all, and finding that out at package time beats finding it out as a
# 401 on a merchant's shop.
if command -v php >/dev/null 2>&1; then
  php "$ROOT/tests/run.php" || die "the test suite refused this tree (see above)"
else
  die "php is not on PATH — refusing to build untested"
fi

# ── Stage exactly what ships ─────────────────────────────────────────────────
#
# An allowlist, not an ignore list. A new dev-only directory added later is then
# absent from the package by default rather than shipped by default, which is the
# safe direction — marketplace review rejects packages carrying build tooling,
# and a stray file is how a local note reaches a merchant's server.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$MODULE"
mkdir -p "$DEST"

SHIP_FILES=("${MODULE}.php" autoload.php config.xml logo.png index.php LICENSE README.md CHANGELOG.md)
SHIP_DIRS=(controllers src vendor views)

for f in "${SHIP_FILES[@]}"; do
  [ -f "$f" ] || die "missing required file: $f"
  cp "$f" "$DEST/"
done

for d in "${SHIP_DIRS[@]}"; do
  [ -d "$d" ] || die "missing required directory: $d"
  cp -R "$d" "$DEST/"
done

# Belt and braces: nothing local, nothing version-controlled, nothing macOS.
#
# `*.local.*` is the pattern rather than any specific filename, deliberately. A
# maintainer's private notes are exactly the kind of file that must never ship,
# and naming one file only protects against that file — the convention is what
# should be enforced.
find "$DEST" \( -name '.DS_Store' -o -name '*.local.*' -o -name '.git*' -o -name '*.orig' \) -delete

# ── PrestaShop wants an index.php in every directory ─────────────────────────
#
# Not decoration: it is what stops a misconfigured server serving a directory
# listing of the module, and marketplace validation checks for it. Generated here
# rather than trusted, so a directory added without one still ships safe.
STUB='<?php
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Location: ../");
exit;
'
while IFS= read -r dir; do
  [ -f "$dir/index.php" ] || printf '%s' "$STUB" > "$dir/index.php"
done < <(find "$DEST" -type d)

# ── The invariants that only exist once it is packaged ───────────────────────
[ -f "$DEST/${MODULE}.php" ] || die "the staged tree has no ${MODULE}/${MODULE}.php"
[ -f "$DEST/logo.png" ]      || die "the staged tree has no logo — the module list will show a broken image"

stray="$(find "$DEST" \( -iname '*.local.*' -o -name '.*' ! -name '.' \) -print -quit)"
[ -z "$stray" ] || die "a local or hidden file reached the package: ${stray#$DEST/}"

# ── Zip ──────────────────────────────────────────────────────────────────────
mkdir -p dist
ARCHIVE="dist/${MODULE}-${VERSION}.zip"
rm -f "$ARCHIVE"

# -X drops extended attributes: on macOS the resource forks and __MACOSX entries
# they produce are noise a merchant's unzip has to survive, and marketplace
# validation flags them.
( cd "$STAGE" && zip -qrX "$ROOT/$ARCHIVE" "$MODULE" )

# ── Verify the ARCHIVE, not the staging directory ────────────────────────────
#
# Everything above proves the tree we built was right. This proves the thing that
# actually gets uploaded is — which is a different claim, and the only one that
# matters to a merchant.
# Read the listing ONCE into a variable and match against that.
#
# `unzip -Z1 … | grep -q …` looks obvious and is wrong under `set -o pipefail`:
# grep -q exits the moment it matches, unzip takes SIGPIPE, and the PIPELINE
# reports failure — so a check that found what it was looking for fails. The
# `&& die` form has the mirror bug, aborting the build when the bad thing is
# absent. Both were real, and the first cost a build that said the archive had
# no main file while `unzip -Z1 | grep` at a prompt showed it plainly.
listing="$(unzip -Z1 "$ARCHIVE")"

top="$(printf '%s\n' "$listing" | awk -F/ '{print $1}' | sort -u)"
[ "$top" = "$MODULE" ] \
  || die "the archive's top level is '${top}', not a single '${MODULE}/' directory"

case "$listing" in
  *"${MODULE}/${MODULE}.php"*) ;;
  *) die "the archive has no ${MODULE}/${MODULE}.php" ;;
esac
case "$listing" in
  *"${MODULE}/config.xml"*) ;;
  *) die "the archive has no ${MODULE}/config.xml" ;;
esac
case "$listing" in
  *__MACOSX*) die "the archive carries __MACOSX entries" ;;
esac

green "ok    archive contains a single ${MODULE}/ root with the module inside"

printf '\n%s  (%s, %s files)\n' \
  "$ARCHIVE" \
  "$(du -h "$ARCHIVE" | cut -f1 | tr -d ' ')" \
  "$(unzip -Z1 "$ARCHIVE" | wc -l | tr -d ' ')"
green "built ${MODULE} ${VERSION}"
