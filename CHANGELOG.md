# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
