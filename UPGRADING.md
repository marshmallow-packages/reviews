# Upgrade Guide

Breaking changes and the work needed to move between versions, newest first.

`marshmallow/reviews` follows semantic versioning. A minor release never
requires code changes. Adding a provider is always a minor release, because
capability is expressed through separate interfaces that a new provider opts
into rather than through one contract every provider has to satisfy.

## Coming in 2.0

Not released yet. Recorded here so nobody plans around the wrong assumption.

### `marshmallow/reviews-kiyoh` is deprecated

Version 2.0 absorbs the review reading side: the feed, the product sync, the
review models and the review response endpoint. When it lands,
[`marshmallow/reviews-kiyoh`](https://packagist.org/packages/marshmallow/reviews-kiyoh)
is marked abandoned on Packagist with `replaced-by` pointing here.

Until then the two packages coexist deliberately. Keep `reviews-kiyoh` for
reading, use this package for invitations and rendering. Nothing you build on
`marshmallow/reviews` 1.x needs to change at 2.0: the `ImportsReviews` and
`RespondsToReviews` interfaces already ship in 1.0, so the Kiyoh provider gains
methods rather than the contract gaining a new shape.

Sites affected: `topwebshop.nl` and `yardy.nl`. Both will need a data migration
from the existing `kiyoh_products` and `kiyoh_reviews` tables. That migration
ships with 2.0.

### Namespace overlap ends

While both packages are installed, their PSR-4 prefixes overlap:
`Marshmallow\Reviews\` here and `Marshmallow\Reviews\Kiyoh\` there. Composer
resolves the longest matching prefix first, so this works, and the bundled
providers deliberately live under `Marshmallow\Reviews\Providers\` to keep it
unambiguous. Once `reviews-kiyoh` is removed the overlap disappears.

## 1.0

First release. Nothing to upgrade from.

If you are replacing an existing integration rather than upgrading this
package, the migration guides are in the [README](README.md#migrating-from-an-ad-hoc-integration):

- from `marshmallow/reviews-kiyoh`
- from a hand written service class
- from an ad hoc Google Customer Reviews snippet

Two things to check while you migrate, because both fail quietly:

1. **Your Kiyoh environment variable name.** `marshmallow/reviews-kiyoh` reads
   `KIYOH_HASH` at runtime through `env()` while its own config file declares
   `KIYOH_INVITE_HASH`, which means it silently stops working under
   `config:cache`. Whichever of the two your site actually sets, the value goes
   into `KIYOH_API_TOKEN` here. On Forge this is a server side change: the repo
   edit alone does nothing.
2. **Your estimated delivery date.** Google Customer Reviews will not render
   without one, and an ad hoc snippet usually has the date hardcoded. Configure
   `reviews.estimated_delivery_date` or implement
   `reviewEstimatedDeliveryDate()` on your order model, then confirm with
   `php artisan reviews:doctor`, which exits non-zero when Google is active and
   neither is set.

## Deprecation policy

- A capability interface is never changed after release. New capabilities are
  new interfaces, so an existing provider keeps compiling.
- A new bundled provider is a minor release.
- Config keys are added, not renamed, within a major version. A key that has to
  change keeps working under its old name for the rest of the major version.
- Anything deprecated is listed here and in [CHANGELOG.md](CHANGELOG.md) at
  least one minor release before it is removed.
