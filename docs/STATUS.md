# Status and next steps

Last updated: 2026-08-11. This is the handover document: what is true right
now, what is decided, and what happens next. `docs/BRIEF.md` is the phase 1
research and design record and explains why things are shaped this way; this
file is where a later session picks the work back up.

## Where things stand

- v1.0 is **code complete and unreleased**. 206 tests, 616 assertions, PHPStan
  level 8, type coverage 100% on Pest 5 (99 floor because of one deliberately
  untyped parameter, see AGENTS.md), Pint clean.
- CI is green: 12 jobs, PHP 8.3/8.4/8.5 x Laravel 12/13 x
  prefer-lowest/prefer-stable.
- Repo: https://github.com/marshmallow-packages/reviews (public, like
  bot-shield). **Not tagged, not on Packagist**, so
  `composer require marshmallow/reviews` does not resolve yet.
- **No production traffic has ever touched this code.** Every Kiyoh request in
  the test suite is Http::fake(). Treat everything as unproven until one real
  site sends one real invitation.

## Decisions still open (Lars)

1. **Version number for the first tag.** Options: `v1.0.0`, or `0.9.x` /
   `v1.0.0-beta.1` until a real site runs it. Recommendation on record: do not
   burn the major before the first integration.
2. **Packagist submission** plus the GitHub webhook. Needs Lars's Packagist
   account.
3. **The real Kiyoh badge snippet.** The bundled badge view is a documented
   placeholder; the real widget snippet lives in a client's Kiyoh dashboard
   (topwebshop.nl or yardy.nl have accounts).

## Next work, in order

1. **Integrate into yardy.nl.** The first real integration and the actual
   proof. It is a delete-and-replace of
   `yardy.nl/app/Services/KiyohService.php::sendReviewInvite()`, and yardy is
   NOT on marshmallow/cart, so it exercises the implement-the-interface path.
   While there:
   - `KIYOH_API_TOKEN` must be set **in Forge** (Site > Environment), not only
     in the repo. The old package's `KIYOH_HASH` vs `KIYOH_INVITE_HASH`
     confusion means checking which one the site actually has.
   - yardy's `sendReviewResponse()` (review moderation) stays: the package has
     no `RespondsToReviews` implementation until v2.0. Only the invite path
     moves.
   - yardy records a `LeadNotification` per sent invite. That bookkeeping stays
     in yardy, alongside the dispatch.
2. **topwebshop.nl waits for v2.0.** It is read-heavy (feed, product sync,
   Nova resource), which the package does not cover yet.
3. **v1.1 WebwinkelKeur, v1.2 Trustpilot, v2.0 import/respond/deprecation,
   v2.1 Trustoo**, per the roadmap table in README.md. Before starting v1.2:
   verify whether Trustpilot API access needs partner approval. That is a
   commercial question, unanswered in phase 1 research.

## Known issues and quirks

- **Type coverage flake on CI.** `pest-plugin-type-coverage` intermittently
  fails with a ParseError in its own generated `.temp/v3.php` on PHP 8.3.
  Seen once (run 31527503830, first attempt), passed on re-run. If it recurs:
  re-run first; if it keeps recurring, pin `pestphp/pest-plugin-type-coverage`
  to a known-good version and file an issue upstream at
  https://github.com/pestphp/pest-plugin-type-coverage.
- **Sentry and Linear are deliberately undeclared for this repo.** Packages do
  not carry a `## Tracking` block (bot-shield has none either); errors surface
  in the consuming application's Sentry. If issue tracking for this package is
  ever wanted, ask Lars which Linear project, never guess.
- **The doctor cannot see per-order data.** A digital-goods order has no
  shipping address, so no delivery country, so Google renders nothing while
  every config check passes. `reviews:doctor` says so explicitly rather than
  staying quiet. There is no fix, only awareness.
- **`NullProvider::invite()` returns `SkipReason::Disabled`**, considered and
  kept: with the master switch off no results are produced at all, so
  "package off" and "null provider chosen" are already distinguishable.

## Session decisions log

Decisions taken during the build, with the reasoning recorded where it lives:

| Decision | Where recorded |
| --- | --- |
| Socialite-shaped manager, capability interfaces, all six ship in v1 | docs/BRIEF.md section 8, README |
| Name `marshmallow/reviews`, providers under `Providers\` to dodge the reviews-kiyoh PSR-4 overlap | docs/BRIEF.md sections 7, 12 |
| Kiyoh + Google + null in v1, read side in v2 | docs/BRIEF.md section 11 |
| Rector manual-only, PHPStan level 8 floor, php ^8.3, Laravel 12+13 | docs/BRIEF.md section 12, items 12-15 |
| Consent gates rendering only; Gate checked inside providers | AGENTS.md, README privacy section |
| Job never throws; exceptions to Sentry, never the log | AGENTS.md, README logging section |
| Type coverage floor 99, why not 100 | AGENTS.md traps section |
| PHPStan catch.neverThrown ignore for the trait | phpstan.neon.dist, comment inline |
| Deprecation policy | UPGRADING.md |
