# Reviews

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marshmallow/reviews.svg?style=flat-square)](https://packagist.org/packages/marshmallow/reviews)
[![Tests](https://img.shields.io/github/actions/workflow/status/marshmallow-packages/reviews/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marshmallow-packages/reviews/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/marshmallow/reviews.svg?style=flat-square)](https://packagist.org/packages/marshmallow/reviews)

Unified review collection for Laravel webshops. One contract for every review
provider, whether it collects reviews through a server side API, through a
snippet in the visitor's browser, or through a link you mail yourself.

```php
use Marshmallow\Reviews\Facades\Reviews;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Data\ReviewInvitation;

SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order));
```

```blade
{{-- On the order confirmation page --}}
<x-reviews::opt-in :order="$order" />

{{-- Anywhere --}}
<x-reviews::badge />
```

## Why this package exists

Every webshop we build uses a different review provider, and until now the
opt-in, the badge and the invitation flow were rebuilt per project. This
package solves it once. The provider becomes a config value.

It resolves providers the way Socialite resolves social logins, so
`Reviews::driver('kiyoh')` and `Reviews::extend()` work exactly as you expect.

## Installation

```bash
composer require marshmallow/reviews
```

```bash
php artisan vendor:publish --tag=reviews-config
```

Nothing happens until you choose a provider. The package ships with the `null`
provider as its default, so installing it changes no behaviour.

## Providers and what they can do

Not every provider can do everything, and the contract says so in types rather
than in documentation. A provider implements only the capability interfaces it
can honour, so "can this send an invitation?" is `instanceof SendsInvitations`,
not a null check on a method that pretends to exist.

| Provider | Send | Show opt-in | Show badge | Import | Respond | Link |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `kiyoh` | yes | - | yes | v2 | v2 | yes |
| `google` | **no** | yes | yes | - | - | - |
| `null` | - | - | - | - | - | - |

Google Customer Reviews has no server side invitation API of any kind. Google
collects the opt-in in the visitor's browser and sends the survey itself. That
is why `GoogleProvider` deliberately does not implement `SendsInvitations`, and
why a fan-out reports it as skipped with `SkipReason::ClientSideOnly` rather
than silently sending nothing.

Coming later: WebwinkelKeur (v1.1), Trustpilot (v1.2), review import and
responses (v2.0), Trustoo (v2.1).

## Configuration

```php
// config/reviews.php

'default' => env('REVIEWS_PROVIDER', 'null'),

// More than one provider can be active. Running Google Customer Reviews for
// the Google seller rating alongside Kiyoh for the badge and the invitation
// email is a normal setup, so the fan-out walks a list.
'active' => ['kiyoh', 'google'],
```

Leave `active` as `null` to use the default provider alone.

### The master switch

```dotenv
REVIEWS_ENABLED=false
```

Turns the package off completely: nothing sends, nothing renders. This is
enforced inside the providers, not only in the fan-out, so
`Reviews::driver('kiyoh')->invite()` will not reach the live API either. Set it
on staging, where inviting a real customer to review a test order is a genuine
hazard.

### Kiyoh and Klantenvertellen

The same platform on two domains. Use whichever your account lives on.

```dotenv
REVIEWS_PROVIDER=kiyoh
KIYOH_BASE_URL=https://www.klantenvertellen.nl
KIYOH_API_TOKEN=your-publication-api-token
KIYOH_LOCATION_ID=1234567
KIYOH_LOCALE=nl
KIYOH_DELAY_DAYS=3
KIYOH_PROFILE_URL=https://www.kiyoh.com/reviews/1234567/your-shop
```

The API token is the **Publication API token** from your Kiyoh dashboard, sent
as the `X-Publication-Api-Token` header.

Two Kiyoh behaviours worth knowing before you debug something that is not
broken:

- **Kiyoh refuses a second invitation to the same address within 30 days.** The
  package reports that as `SkipReason::Duplicate`, not a failure, because it is
  the provider working as designed.
- **Kiyoh's language list is not ISO-639-1.** It is a fixed list of 29 values,
  some bare (`nl`, `de`) and some regional (`es-ES`, `pt-PT`, `nn-NO`). The
  package maps your app locale onto it, so `en_GB` becomes `en` and `es`
  becomes `es-ES`. An unmappable locale falls back to `KIYOH_LOCALE`.

`KIYOH_SKIP_WEEKENDS` (default true) pushes an invitation whose send date lands
on a Saturday or Sunday to the Monday, so it does not arrive in a weekend inbox.

**The Kiyoh badge view is a placeholder.** There is no generic Kiyoh badge that
can be generated from an API token and a location id: the real one is an
account specific snippet you copy out of the Kiyoh dashboard, and it differs
per widget. The bundled view renders a link to your public profile carrying the
location id as a data attribute. To use the real widget:

```bash
php artisan vendor:publish --tag=reviews-views
```

then replace `resources/views/vendor/reviews/kiyoh/badge.blade.php` with your
own snippet.

### Google Customer Reviews

```dotenv
REVIEWS_PROVIDER=google
GOOGLE_MERCHANT_ID=123456789
GOOGLE_OPT_IN_STYLE=CENTER_DIALOG
GOOGLE_BADGE_POSITION=BOTTOM_RIGHT
```

Getting to a merchant id is a sequence of steps in Google's own products, and
none of them are in this package:

1. **Create a Google Merchant Center account** at
   [merchants.google.com](https://merchants.google.com) if you do not have one.
2. **Verify and claim your domain** under Business information, Website. Google
   will not enable the programme for an unverified domain.
3. **Enable Customer Reviews.** In Merchant Center, go to Growth, then Manage
   programmes, and enable Customer Reviews. It can take a few days to be
   approved.
4. **Read your merchant id.** It is shown in the top right of Merchant Center,
   next to the account name. It is the number you put in `GOOGLE_MERCHANT_ID`.

Then place the opt-in on your order confirmation page:

```blade
<x-reviews::opt-in :order="$order" />
```

`GOOGLE_OPT_IN_STYLE` accepts `CENTER_DIALOG`, `BOTTOM_RIGHT_DIALOG`,
`BOTTOM_LEFT_DIALOG`, `BOTTOM_TRAY` and `TOP_BAR`. Google's own guidance is
that a centred dialog converts best.

#### Google needs an estimated delivery date

Google requires `estimated_delivery_date`, and it decides **when** the survey is
sent. Our order models have no such concept, so you have to supply it. If you
do not, the opt-in renders nothing at all, on purpose: a snippet missing a
required field fails silently in the visitor's browser, which is worse than
rendering nothing you can see.

Answer for all orders at once with a config callback:

```php
// config/reviews.php
'estimated_delivery_date' => fn ($order) => now()->addWeekdays(3)->toImmutable(),
```

Or answer per order by implementing the method on your model, which wins over
the config:

```php
public function reviewEstimatedDeliveryDate(): ?CarbonImmutable
{
    return $this->shippingMethod?->estimated_delivery_at?->toImmutable();
}
```

`php artisan reviews:doctor` fails with a non-zero exit code when Google is
active and neither is configured.

#### Product ratings need GTINs

Google only collects **product** ratings when the opt-in carries GTINs; without
them you get seller ratings only. The package reads them from `products.ni`,
which is where `marshmallow/products` stores the GTIN or EAN. Products without
one are simply left out.

## Integrating with our e-commerce packages

For a site on `marshmallow/cart`, the whole integration is one trait:

```php
use Marshmallow\Reviews\Concerns\DerivesReviewableOrder;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

class Order extends \Marshmallow\Ecommerce\Cart\Models\Order implements ReviewableOrder
{
    use DerivesReviewableOrder;
}
```

The trait derives everything from cart conventions:

| Contract method | Derived from |
| --- | --- |
| `reviewerEmail()` | `customer.email` |
| `reviewerFirstName()` / `LastName()` | `customer.first_name` / `last_name` |
| `reviewOrderReference()` | `shopping_cart_display_id`, falling back to the primary key |
| `reviewCountryCode()` | `shippingAddress.country.alpha2` |
| `reviewCity()` | `shippingAddress.city` |
| `reviewOrderTotalInCents()` | `price_including_vat` |
| `reviewProducts()` | `items`, with the GTIN from `product.ni` |
| `reviewLocale()` | the app locale |
| `reviewEstimatedDeliveryDate()` | the config resolver, see above |

Order lines with no product, which is how the cart stores its shipping line,
are left out. So are lines with a quantity below one.

`marshmallow/cart` is a **suggest**, never a require. Nothing in the trait type
hints a cart class, and every derivation degrades to null rather than erroring,
so the same trait works on a model that has none of these relations. A project
not on our e-commerce stack implements `ReviewableOrder` directly instead.

If your columns differ, adjust config rather than overriding methods:

```php
'order' => [
    'reference_column' => 'order_number',
    'gtin_column' => 'ean',
],
```

## Sending invitations

Invitations are queued so a provider outage can never slow down or break a
checkout.

```php
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;

SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order));

// Or target one provider
SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order), 'kiyoh');
```

**The job never throws.** A failing provider is logged at warning level and
swallowed. A review invitation is the least important thing happening around a
checkout, and it should never fail a job, retry a fan-out that half succeeded,
or fill `failed_jobs` with noise nobody acts on.

### Triggering from an order event

Off by default, because which event means "this order is really done" differs
per site, and getting it wrong invites people who never paid.

`marshmallow/cart` has no completed-order event: `OrderCreated` fires **before**
payment. `PaymentStatusPaid` from `marshmallow/payable` is the closer proxy, but
it carries a `Payment` rather than an `Order`, so mapping the event to an order
is your call:

```php
'events' => [
    'enabled' => true,
    'listen' => [
        \Marshmallow\Payable\Events\PaymentStatusPaid::class,
    ],
    'resolve_order' => fn ($event) => $event->payment->payable,
],
```

Events whose public `$order` property already holds a `ReviewableOrder` need no
resolver.

## Custom providers

`Reviews::extend()` works exactly like Socialite's, in your
`AppServiceProvider::boot()`:

```php
Reviews::extend('feedbackcompany', fn ($app) => new FeedbackCompanyProvider(
    $app['config']['reviews.providers.feedbackcompany'],
));
```

Add `'feedbackcompany'` to `reviews.active` and it joins the fan-out. A custom
provider is a first class citizen: resolvable by name, matched by
`supporting()`, and eligible for the Blade components.

The same call **overrides a bundled provider**, because the manager consults
custom creators before its own:

```php
Reviews::extend('kiyoh', fn ($app) => new OurPatchedKiyohProvider(...));
```

Implement `Marshmallow\Reviews\Contracts\ReviewProvider` plus whichever
capability interfaces apply: `SendsInvitations`, `RendersOptIn`,
`RendersBadge`, `ImportsReviews`, `RespondsToReviews`, `ProvidesReviewLink`.
Return raw markup by wrapping it in `Marshmallow\Reviews\Support\Html`.

## Testing

```php
use Marshmallow\Reviews\Facades\Reviews;

Reviews::fake();

// exercise your checkout

Reviews::assertInvited('ORDER-1234');
Reviews::assertInvitedTimes(1);
Reviews::assertNothingInvited();
```

Script a provider outage without an HTTP layer to fake:

```php
Reviews::fake()->shouldFail('Kiyoh is down');
```

## Diagnostics

```bash
php artisan reviews:doctor
```

Read-only, safe on production. It reports the active providers and their
capabilities, and fails on the silent problems: Google active with no delivery
date resolver, a provider that renders client side with no consent callback, an
active provider that is not configured.

## Privacy and consent

### What leaves your system

Put this in the client's data processing agreement.

| Provider | Data sent | To whom | Sent from |
| --- | --- | --- | --- |
| Kiyoh | email, first name, last name, city, order reference, product codes, locale | Kiyoh B.V. / Klantenvertellen, EU | your server |
| Google | merchant id, order id, **email**, delivery country, estimated delivery date, GTINs | Google LLC, US | **the visitor's browser** |

### Consent gates rendering, not sending

The `consent` callback gates client side rendering only:

```php
'consent' => fn () => Cookiebot::hasConsent('marketing'),
```

That distinction is deliberate. Posting an invitation to a provider's API from
your server is a controller to processor transfer under your DPA, not a cookie
placement, so a cookie banner has no bearing on it. Google's opt-in module
loads Google's JavaScript, sets Google's cookies, and transfers the customer's
email address to a US controller from the visitor's own browser. That is what
consent is for, and Google is the provider that needs it.

Providers that render into the browser check consent for themselves as well as
in the fan-out, so `Reviews::driver('google')->badge()` is not a way around the
banner.

### Logging

Nothing this package logs contains an email address, a customer name or a city.
Every log line goes through a `context()` method built to exclude them, and
`InvitationResult` can only be constructed through factories, so a provider
cannot smuggle personal data into a message by accident. The Kiyoh provider
additionally scrubs email addresses out of Kiyoh's own error bodies, because a
provider is free to quote the address it rejected back at you.

Successful invitations are not logged at all unless you ask:

```dotenv
REVIEWS_LOG_SUCCESSES=true
```

### Exceptions go to Sentry, not to the log

An unexpected exception from a provider is reported to Sentry in full, while
the log line stays redacted. A `Throwable` is not ours: an HTTP client that
echoes the request body would put a customer's email address into a log file
that gets shipped, rotated and read by anyone with server access. Sentry is
access controlled, does its own scrubbing, and is where someone actually looks.

This matters because the job never rethrows. Without it, a genuine bug in a
provider would be invisible: nothing raised, and nothing in the log but a class
name. It does nothing when Sentry is not installed, and it never requires it.

```dotenv
REVIEWS_REPORT_EXCEPTIONS=false
```

## Migrating from an ad hoc integration

### From `marshmallow/reviews-kiyoh`

`marshmallow/reviews-kiyoh` keeps working and is not deprecated until v2.0,
when this package absorbs the review reading side. Until then the two coexist:
use this package for invitations, keep the old one for the feed, the product
sync and the Nova resource.

Invitations move like this:

```php
// Before
KiyohInvite::email($order->customer->email)
    ->firstName($order->customer->first_name)
    ->refCode($order->id)
    ->delayIgnoreWeekend(3)
    ->invite();

// After
SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order));
```

Check your `.env` while you are there. The old package reads `KIYOH_HASH` at
runtime through `env()` while its config file declares `KIYOH_INVITE_HASH`,
which means it silently stops working under `config:cache`. Whichever of the
two your site actually sets, the value goes into `KIYOH_API_TOKEN` here.

Behaviour that changes on purpose:

- The old `invite()` threw on failure. This one returns an `InvitationResult`
  and the job swallows failures, so a Kiyoh outage no longer surfaces as an
  exception in your checkout.
- Invitations are queued rather than sent inline.
- A repeat invitation within 30 days is a skip, not an exception.

### From a hand written service class

If you have something like `app/Services/KiyohService.php` posting to
`/v1/invite/external` directly, delete it and use the package. The one thing
worth keeping is any bookkeeping you do around the send, such as recording a
notification against the customer. Move that to a listener on your own event,
or dispatch the job yourself and record alongside it.

### From an ad hoc Google snippet

If the Google opt-in is currently pasted into a Blade template, replace the
whole block with `<x-reviews::opt-in :order="$order" />` and move the merchant
id to `GOOGLE_MERCHANT_ID`. Then configure an estimated delivery date resolver:
a hardcoded snippet usually has a hardcoded date, and that is the part that
silently degrades.

## Testing this package

```bash
composer test          # phpstan, pint, type coverage, pest
composer test:unit     # pest only
composer analyse       # phpstan level 8
composer lint          # pint
composer refactor      # rector, run deliberately, not a CI gate
```

## Credits

- [Marshmallow](https://github.com/marshmallow-packages)

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
