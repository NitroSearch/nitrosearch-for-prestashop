# Contributing

Thanks for taking the time. Bug reports are as useful as patches.

## Reporting a bug

Open an issue with:

- Your PrestaShop and PHP versions, and the module version.
- What you expected, and what happened instead.
- Whether the shop syncs via cron or the page-load fallback.
- Anything in the module's **Last error** field on its configure screen.

**Never paste your sync secret, your cron token or your scoped search key into an issue.** If a
credential has been exposed, disconnect and reconnect the shop from the configure screen — that
rotates it.

## Working on the module

The module is plain PHP with no build step and no Composer dependencies. The adapter kit under
`vendor/nitrosearch-contract/` is **vendored deliberately** — a PrestaShop module is installed by
uploading a ZIP, so there is no `composer install` on a merchant's shop and a module that resolves
dependencies at install time fails on most hosts. Do not edit it by hand; it is generated, and it
carries the currency-exponent table that keeps prices correct.

`tests/fixtures/` holds conformance cases for the ingest contract: each is a payload and the
document the service produces from it. They need no secrets, no network and no account.

```bash
php tests/run.php
```

That is the whole suite — no Composer, no PHPUnit, deliberately. This module ships as a ZIP with no
build step and no dependencies, and a dev-only dependency would mean a lockfile and a packaging rule
to keep it out of the archive. `./bin/build-module.sh` runs it, and so does CI on every push and
pull request.

**What it covers, and what it does not.** It covers the pure parts where being wrong is silent and
expensive: the HMAC canonicalisation (a drift there is a 401, not a negotiation), the
proof-of-control hash, and the vendored currency exponent table. It cannot boot PrestaShop, so
hooks, the outbox and the drain are **not** covered — for those, install the built ZIP into a shop
that has never seen the module and say in the pull request what you saw. A green tick here is not a
claim that the module works.

## Code style

Follow the surrounding code. Two things are load-bearing rather than stylistic:

- **`src/Support/Hmac.php` must stay byte-compatible with the service's verifier.** The canonical
  string and header names are a wire contract; a change on one side alone is a 401, not a
  negotiation.
- **Hooks must not do network work.** They write to the outbox and return. Anything that makes a
  merchant's save wait on our service belongs in the drain.

## Pull requests

Keep them focused, explain the reasoning in the description rather than only the change, and say how
you verified it against a real shop.
