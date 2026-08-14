# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [Unreleased]

### Fixed

- **A shop that upgraded the module in place had no order-report table, and lost every attributed
  sale.** PrestaShop runs a module's `install()` when it is installed and never when it is upgraded,
  and this module has no upgrade script — so the order-report table, added in 1.1.0, only ever
  existed on shops that installed at 1.1.0 or later. On any shop that started at 1.0.0 every report
  INSERT failed, the failure was swallowed by the seal that stops analytics breaking a shopper's
  checkout, and search-attributed revenue silently read zero forever with nothing in the back office
  saying so. Both tables are now created on first use, not only at install.

- **A custom accent colour left its label text unreadable.** The module worked out whether dark or
  light text belonged on the merchant's chosen accent, sent it under the wrong name, and the search
  panel never saw it — falling back to white, which is right for a dark accent and invisible on a
  pale one.

## [1.2.1] — 2026-08-10

### Fixed

- **Orders that came from a search are no longer thrown away when the service is busy or briefly
  unavailable.** The module reported an order once and treated any refusal as final — so an order
  placed while your shop was still being verified, or while the service was rate-limiting a burst of
  sales, was dropped and never counted. The worst case was the one that matters most: during a rush,
  every order past the service's per-minute limit was discarded, so **your busiest hour reported the
  least revenue**. Reports are now kept and retried with widening gaps, and if one truly cannot be
  delivered it is recorded in **Advanced Parameters → Logs** rather than vanishing.

- **Revenue can no longer be counted twice.** The timestamp identifying an order was recalculated
  each time a report was sent, so a retry that happened across a daylight-saving change looked like
  a second, different order. The timestamp is now fixed when the order is queued and sent unchanged.

- **Reports are no longer kept longer than they can be used.** The queue held an order for fourteen
  days, past the point the service will accept its timestamp, so the oldest reports could never
  land at all.

### Changed

- **The module now has a test suite, and it runs on every change.** The conformance fixtures under
  `tests/` had been in this repository since the module was written, describing exactly how prices,
  currencies and visibility must behave — and nothing ever ran them, because there was no runner and
  no CI. Both defects fixed in 1.2.0 were in that area.

  `php tests/run.php` now runs them, `./bin/build-module.sh` runs it before packaging, and CI runs
  it on PHP 8.1, 8.2 and 8.3. Nothing about the module a merchant installs changes — no test
  material is in the archive.

## [1.2.0] — 2026-08-05

### Added

- **Your shop now keeps its search key fresh on its own.** The key your storefront searches with has
  a lifetime, and your shop fetches a new one once a day, long before the old one runs out.

  Previously it only ever asked for a key when it had none at all — so a shop that connected and was
  then simply used normally would, about a year later, find storefront search returning nothing.
  There was no error, and the Configure screen still reported a healthy connection. If your shop has
  been connected for a while, this update is what stops that happening.

  Shops without a scheduled sync are covered too: the check now also runs on the occasional
  storefront page view that already keeps the sync moving. It has its own daily clock and a short
  timeout, so it cannot slow a page down or delay a sync.

### Fixed

- **The storefront configuration is now properly escaped where it is written into the page.** The
  widget is set up by a small block of data placed directly in your shop's HTML. The one
  free-text value that reaches it — the search-box selector, if you have set one in the module's
  settings — was not being escaped, so a selector containing a closing script tag could have ended
  the block early and had whatever followed it treated as part of the page. The escaping was in
  place but had no effect: it replaced a character with itself. It is now done by PHP's own
  encoder, and the build refuses to produce an archive if it is ever missing again.

  Nothing you have configured needs changing, and the widget behaves exactly as before.

- **Requests no longer emit a deprecation notice on PHP 8.5.** The module closed each network
  handle explicitly, which has done nothing since PHP 8.0 and is deprecated as of 8.5. On a shop
  with error display switched on, the notice could be printed into the front office's own markup.

## [1.1.0] — 2026-08-04

### Added

- **Search → order attribution.** When a shopper adds to cart from the search panel, that product is
  noted against their own cart; when the order is placed, the items that came from a search make up
  the attributed slice and its value is reported. **The real order id never leaves the shop** — it is
  hashed first — and nothing about the customer, address, payment or the rest of the basket is
  included. Honours the "Share anonymous search data" switch. Reporting rides the background sync,
  never checkout: a slow or unreachable service must never slow, and certainly never fail, a sale.
- **Appearance settings** — density, colours, corners, accent, panel width, results per dropdown and
  where filters sit. Choices are resolved to plain style values before the page is built, so the
  shared storefront bundle never learns a preset name and changing one needs no update.
- **Your shop's scope is stated on the Configure screen.** NitroSearch indexes one shop, in one
  language, at one currency. The screen now names which, and lists the currencies and (on multistore)
  the shops that are therefore *not* indexed, rather than leaving a merchant to discover it.

### Fixed

- **Prices and names no longer depend on who triggered the sync.** They were read from the ambient
  request context — the shopper's in a front-office request, an employee's in the back office, the
  default under cron — so the same product could be indexed at one currency by a page-load sync and
  another by a scheduled one. Both are now pinned to the shop's own defaults.

## [1.0.0] — 2026-08-04

### Added

- First release. Connects a PrestaShop shop to NitroSearch, keeps its catalogue in sync, and puts
  the storefront search widget on the theme's existing search box.
  - **A Configure screen** that leads with status rather than settings: connected or not, how much
    of the plan is used, what is still waiting to be sent, when the last send was and what went
    wrong if anything did — then Connect, Re-send everything, Send now and Disconnect. It never
    renders the sync secret or the search key; the cron address is shown because it is the one
    thing a merchant has to copy.
  - **Confirming the domain asks before it acts.** The service confirms a shop by making a request
    to it from the outside, so it may already have succeeded without this module being involved.
    Pressing "Try again" polls for that answer first and only triggers a fresh check if the answer
    is still no.
  - **The theme's own search suggestions step aside**, but only once ours have actually appeared.
    PrestaShop's search bar binds a suggestions list to the same input, so without this a shopper
    sees two stacked lists showing different results. Doing it on page load instead would mean a
    shop whose widget failed to load had no suggestions at all — worse than two. Can be turned off.
  - **A local outbox.** Catalogue hooks write a coalesced row and do no network work, so saves,
    bulk edits, imports and checkout stay fast — and the shop keeps recording changes while the
    service is unreachable.
  - **A full catalogue walk that does not depend on hooks**, because PrestaShop's hooks are not
    complete: the CSV importer, direct SQL and some third-party modules change products without
    firing anything a module can see. The walk is chunked, resumable and keyset-paged, so a large
    catalogue never has to be enumerated in one request.
  - **Two ways to drain.** A token-gated cron endpoint, and — for shops with no cron — a bounded
    fallback that runs after a shopper's page has already been sent, at most one batch every 90
    seconds.
  - **Proof-of-control endpoint** so NitroSearch can confirm the shop controls its own hostname
    before indexing anything.
  - **Resync responder**: the service can ask the shop to re-send its catalogue, which is what stops
    an item that failed on arrival from being missing forever.
  - **Combinations fold into their parent product** — one product is one result and one unit of your
    plan, however many combinations it has, with every SKU searchable and the price indexed as a
    range.
  - **Prices are sent as the shop displays them**, tax included or excluded per your own settings,
    in integer minor units with the currency's own exponent — so yen and dinar are correct rather
    than scaled by a hundred out of habit.

[Unreleased]: https://github.com/NitroSearch/nitrosearch-for-prestashop/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/NitroSearch/nitrosearch-for-prestashop/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/NitroSearch/nitrosearch-for-prestashop/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/NitroSearch/nitrosearch-for-prestashop/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/NitroSearch/nitrosearch-for-prestashop/releases/tag/v1.0.0
