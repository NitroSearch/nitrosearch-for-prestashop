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
document the service produces from it. Run them in CI — they need no secrets, no network and no
account.

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
