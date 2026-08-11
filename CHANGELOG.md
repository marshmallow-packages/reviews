# Release Notes

All notable changes to `marshmallow/reviews` are documented here. Breaking
changes and the work needed to move between major versions live in
[UPGRADING.md](UPGRADING.md).

## v1.0.0 - unreleased

First release. Unified review collection across providers, resolved the way
Socialite resolves social logins.

### Added

- `Reviews` manager on `Illuminate\Support\Manager`. `Reviews::driver('kiyoh')`,
  `Reviews::extend()` to register a custom provider or override a bundled one.
- Six capability interfaces, all shipping in 1.0 so no later addition is a
  breaking change: `SendsInvitations`, `RendersOptIn`, `RendersBadge`,
  `ImportsReviews`, `RespondsToReviews`, `ProvidesReviewLink`.
- Providers: **Kiyoh** (send, badge, link), **Google Customer Reviews**
  (opt-in, badge), **null** (every interface, does nothing).
- A fan-out layer over a list of active providers, so a site can run Google
  Customer Reviews for the Google seller rating alongside Kiyoh for the badge
  and the invitation email.
- `ReviewableOrder` contract and the `DerivesReviewableOrder` trait, deriving
  everything from `marshmallow/cart` conventions without depending on it.
- Queued `SendReviewInvitation` job that never throws, and an optional event
  listener that is off by default.
- Blade components `<x-reviews::opt-in />` and `<x-reviews::badge />`, both
  rendering nothing at all when there is nothing to render.
- Consent callback gating client side rendering, checked both in the fan-out
  and inside the providers that render into the browser.
- `Reviews::fake()` test double with invitation assertions.
- `php artisan reviews:doctor`, a read-only diagnostic for the silent failures.

### Notes

- Google Customer Reviews has no server side invitation API. It renders an
  opt-in in the browser, and a fan-out reports it as skipped with
  `ClientSideOnly` rather than quietly sending nothing.
- Google requires an estimated delivery date, which our order models do not
  have. Supply it through the `estimated_delivery_date` config callback or by
  implementing `reviewEstimatedDeliveryDate()` on your order model. Without it
  the opt-in renders nothing, on purpose, and `reviews:doctor` fails.
- Nothing this package logs contains an email address, a customer name or a
  city.
- The Kiyoh badge view is a placeholder: the real badge is an account specific
  snippet from the Kiyoh dashboard. Publish the views and replace it.
- `marshmallow/reviews-kiyoh` is not deprecated yet. It keeps the review
  reading side until v2.0 absorbs it.
