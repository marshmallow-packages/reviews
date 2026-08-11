![marshmallow.](https://marshmallow.dev/cdn/media/logo-red-237x46.png "marshmallow.")

# Reviews

[![Latest Version on Packagist](https://img.shields.io/packagist/v/marshmallow/reviews.svg?style=flat-square)](https://packagist.org/packages/marshmallow/reviews)
[![Tests](https://img.shields.io/github/actions/workflow/status/marshmallow-packages/reviews/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/marshmallow-packages/reviews/actions/workflows/tests.yml)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/marshmallow/reviews.svg?style=flat-square)](https://packagist.org/packages/marshmallow/reviews)
[![Total Downloads](https://img.shields.io/packagist/dt/marshmallow/reviews.svg?style=flat-square)](https://packagist.org/packages/marshmallow/reviews)

Unified review collection for Laravel webshops: server side invitations,
opt-in and badge rendering, and review import across Kiyoh, Google Customer
Reviews and more.

Every webshop uses a different review provider, and until now the opt-in, the
badge and the invitation flow were rebuilt per project. This package solves it
once: the provider becomes a config value.

Providers are resolved the way [Socialite](https://laravel.com/docs/socialite)
resolves social logins, so `Reviews::driver('kiyoh')` and `Reviews::extend()`
work exactly as you expect, and `extend()` overrides a bundled provider as well
as adding a new one.

Not every provider can do everything, and the contract says so in types rather
than in documentation. A provider that cannot send invitations does not
implement `SendsInvitations`, so the question is answered by the type system
instead of by a method that returns null and hopes you notice.

```php
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;

SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order));
```

```blade
{{-- On the order confirmation page --}}
<x-reviews::opt-in :order="$order" />

{{-- Anywhere --}}
<x-reviews::badge />
```

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- [`marshmallow/cart`](https://packagist.org/packages/marshmallow/cart), optional. It is a composer `suggest`, not a `require`. The order trait derives everything from its conventions but never depends on it, so a project that is not on our e-commerce stack implements one interface instead.
- [`sentry/sentry-laravel`](https://docs.sentry.io/platforms/php/guides/laravel/), optional. When present, swallowed exceptions are reported there. When absent, nothing happens.

## Installation

```bash
composer require marshmallow/reviews
```

```bash
php artisan vendor:publish --tag=reviews-config
```

Nothing happens until you choose a provider. The package ships with the `null`
provider as its default, so installing it changes no behaviour on any page.

### Publishing other resources

| Tag | Publishes | When you need it |
| --- | --- | --- |
| `reviews-config` | `config/reviews.php` | Always. |
| `reviews-views` | `resources/views/vendor/reviews` | To replace the Kiyoh badge placeholder with your account's real widget snippet, or to restyle the Google markup. |
| `reviews-lang` | `lang/vendor/reviews` | To reword the `reviews:doctor` output. |
| `reviews` | All of the above | Rarely. Prefer the specific tags. |

## Providers and what they can do

| Provider | Send | Show opt-in | Show badge | Import | Respond | Link |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `kiyoh` | yes | - | yes | v2.0 | v2.0 | yes |
| `google` | **no** | yes | yes | - | - | - |
| `null` | yes | yes | yes | yes | yes | yes |

The `null` provider implements every interface on purpose and does nothing with
any of them: every invitation comes back as a skip and every render returns
nothing. That is what makes "the whole contract is satisfiable" a compile time
fact rather than a claim, and it gives `ImportsReviews` and `RespondsToReviews`
a test subject from v1.

[Google Customer Reviews](https://support.google.com/merchants/answer/7124322)
has no server side invitation API of any kind. Google collects the opt-in in
the visitor's browser and sends the survey itself. That is why `GoogleProvider`
deliberately does not implement `SendsInvitations`, and why a fan-out reports
it as skipped with `SkipReason::ClientSideOnly` rather than silently sending
nothing and looking successful.

## Configuration

### The master switch

```dotenv
REVIEWS_ENABLED=false
```

Turns the package off completely: nothing sends, nothing renders. This is
enforced inside the providers, not only in the fan-out, so
`Reviews::driver('kiyoh')->invite()` will not reach the live API either. Set it
on staging, where inviting a real customer to review a test order is a genuine
hazard.

### Choosing providers

```php
'default' => env('REVIEWS_PROVIDER', 'null'),

// More than one provider can be active. Running Google Customer Reviews for
// the Google seller rating alongside Kiyoh for the badge and the invitation
// email is a normal setup, so the fan-out walks a list.
'active' => ['kiyoh', 'google'],
```

Leave `active` as `null` to use the default provider alone, which makes the
package behave exactly like Socialite.

A name in `active` that cannot be resolved is logged and skipped rather than
raised. The Blade components run on the order confirmation page, and a typo in
config turning into a 500 there, after the customer has already paid, is the
worst failure this package could have. `reviews:doctor` is where a typo gets
reported loudly.

### Reference

Full documentation lives in the published `config/reviews.php`. The top-level
keys:

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `REVIEWS_ENABLED` | `true` | Master switch. Nothing sends, nothing renders. |
| `default` | `REVIEWS_PROVIDER` | `null` | Provider resolved by `Reviews::driver()` with no argument. |
| `active` | | `null` | Providers taking part in the fan-out. `null` means the default alone. |
| `consent` | | `null` | Callable returning bool. Gates client side rendering only. |
| `estimated_delivery_date` | | `null` | Callable taking a `ReviewableOrder`, returning a date or null. |
| `order.*` | | cart names | Column and relation names the order trait reads. |
| `events.enabled` | `REVIEWS_LISTEN_TO_EVENTS` | `false` | Whether the listener is bound at all. |
| `events.listen` | | `[]` | Event classes that trigger an invitation. |
| `events.resolve_order` | | `null` | Callable mapping an event to a `ReviewableOrder`. |
| `queue.connection` | `REVIEWS_QUEUE_CONNECTION` | app default | Connection the invitation job runs on. |
| `queue.queue` | `REVIEWS_QUEUE` | app default | Queue the invitation job runs on. |
| `queue.tries` | `REVIEWS_QUEUE_TRIES` | `3` | Job attempts. Only serialisation failures can retry. |
| `queue.backoff` | | `[60, 300, 900]` | Seconds between attempts. |
| `http.timeout` | `REVIEWS_HTTP_TIMEOUT` | `10` | Seconds before a provider request gives up. |
| `http.attempts` | `REVIEWS_HTTP_ATTEMPTS` | `2` | Total attempts, not retries on top of the first. |
| `http.retry_sleep_milliseconds` | `REVIEWS_HTTP_RETRY_SLEEP` | `250` | Pause between attempts. |
| `log.channel` | `REVIEWS_LOG_CHANNEL` | app default | Channel the package writes to. |
| `log.successes` | `REVIEWS_LOG_SUCCESSES` | `false` | Log successful invitations, not only failures. |
| `log.report_exceptions` | `REVIEWS_REPORT_EXCEPTIONS` | `true` | Report swallowed exceptions to Sentry. |

Kiyoh, under `providers.kiyoh`:

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `base_url` | `KIYOH_BASE_URL` | `https://www.klantenvertellen.nl` | Use the domain your account lives on. |
| `api_token` | `KIYOH_API_TOKEN` | `null` | Publication API token, sent as `X-Publication-Api-Token`. |
| `location_id` | `KIYOH_LOCATION_ID` | `null` | Your Kiyoh location id. |
| `locale` | `KIYOH_LOCALE` | `nl` | Fallback when the order locale cannot be mapped. |
| `delay_days` | `KIYOH_DELAY_DAYS` | `3` | Days between the order and the invitation email. |
| `skip_weekends` | `KIYOH_SKIP_WEEKENDS` | `true` | Push a send date off Saturday and Sunday. |
| `profile_url` | `KIYOH_PROFILE_URL` | `null` | Public profile, used for the badge and the review link. |

Google, under `providers.google`:

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `merchant_id` | `GOOGLE_MERCHANT_ID` | `null` | Merchant Center account id. Without it nothing renders. |
| `opt_in_style` | `GOOGLE_OPT_IN_STYLE` | `CENTER_DIALOG` | Also `BOTTOM_RIGHT_DIALOG`, `BOTTOM_LEFT_DIALOG`, `BOTTOM_TRAY`, `TOP_BAR`. |
| `badge_position` | `GOOGLE_BADGE_POSITION` | `BOTTOM_RIGHT` | Also `BOTTOM_LEFT`, `INLINE`. |
| `language` | `GOOGLE_REVIEWS_LANGUAGE` | app locale | Language of the module itself. |

## Features and defaults

Installing the package changes nothing until you choose a provider. Everything
that could surprise a visitor is off until you ask for it.

| Feature | Default | Notes |
| --- | --- | --- |
| Package enabled | on | But the default provider is `null`, which does nothing. |
| Default provider | `null` | Sends nothing, renders nothing. |
| Invitations queued | on | So a provider outage cannot slow a checkout. |
| Event listener | **off** | Which event means "order is done" differs per site. |
| Consent callback | none | Rendering is allowed until you wire a banner to it. |
| Success logging | **off** | Failures are always logged. |
| Exception reporting to Sentry | on | Silently does nothing without Sentry. |
| Kiyoh weekend skipping | on | |
| HTTP attempts | 2 | One try and one retry. |

## Kiyoh and Klantenvertellen

[Kiyoh](https://www.kiyoh.com) and
[Klantenvertellen](https://www.klantenvertellen.nl) are the same platform on
two domains. Use whichever your account lives on.

```dotenv
REVIEWS_PROVIDER=kiyoh
KIYOH_BASE_URL=https://www.klantenvertellen.nl
KIYOH_API_TOKEN=your-publication-api-token
KIYOH_LOCATION_ID=1234567
KIYOH_PROFILE_URL=https://www.kiyoh.com/reviews/1234567/your-shop
```

The token is the **Publication API token** from your Kiyoh dashboard, under the
API or integrations section.

Two Kiyoh behaviours worth knowing before you debug something that is not
broken:

- **Kiyoh refuses a second invitation to the same address within 30 days.** The
  package reports that as `SkipReason::Duplicate`, not a failure, because it is
  the provider working as designed.
- **Kiyoh's language list is not ISO-639-1.** It is a fixed list of 29 values,
  some bare (`nl`, `de`) and some regional (`es-ES`, `pt-PT`, `nn-NO`). The
  package maps your app locale onto it, so `en_GB` becomes `en` and `es`
  becomes `es-ES`. An unmappable locale falls back to `KIYOH_LOCALE`.

### The Kiyoh badge is a placeholder

There is no generic Kiyoh badge that can be generated from an API token and a
location id: the real one is an account specific snippet you copy out of the
Kiyoh dashboard, and it differs per widget. The bundled view renders a link to
your public profile carrying the location id as a data attribute.

```bash
php artisan vendor:publish --tag=reviews-views
```

Then replace `resources/views/vendor/reviews/kiyoh/badge.blade.php` with your
own snippet.

## Google Customer Reviews

```dotenv
REVIEWS_PROVIDER=google
GOOGLE_MERCHANT_ID=123456789
```

Getting to a merchant id is a sequence of steps in Google's own products, none
of which are in this package:

1. **Create a Google Merchant Center account** at
   [merchants.google.com](https://merchants.google.com).
2. **Verify and claim your domain**, under Business information, Website.
   Google will not enable the programme for an unverified domain. Verification
   runs through [Google Search Console](https://search.google.com/search-console).
3. **Enable Customer Reviews**, under Growth, then Manage programmes. Approval
   can take a few days.
4. **Read your merchant id** from the top right of Merchant Center, next to the
   account name.

Google's own integration documentation is worth reading alongside this:
[the programme overview](https://support.google.com/merchants/answer/7124322)
and [integrating the survey opt-in module](https://support.google.com/merchants/answer/14629205).
The second covers the optional `products` field, which is what turns seller
ratings into product ratings.

Then place the opt-in on your order confirmation page, and nowhere else:

```blade
<x-reviews::opt-in :order="$order" />
```

### Google needs an estimated delivery date

Google requires `estimated_delivery_date`, and it decides **when** the survey is
sent. Our order models have no such concept, so you have to supply it. If you
do not, the opt-in renders nothing at all, on purpose: a snippet missing a
required field fails silently in the visitor's browser, which is worse than
rendering nothing you can see.

Answer for all orders at once with a config callback:

```php
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

`php artisan reviews:doctor` exits non-zero when Google is active and neither
is configured.

### What the doctor cannot check

Google also needs an email address and a **delivery country** on every
invitation, and the country comes from the shipping address. An order without
one, a digital product for instance, collects no review even though your
configuration is perfect. No config check can see that, so the doctor says so
rather than staying quiet about it.

### Product ratings need GTINs

Google only collects **product** ratings when the opt-in carries GTINs. Without
them you get seller ratings only. The package reads them from `products.ni`,
which is where [`marshmallow/products`](https://packagist.org/packages/marshmallow/products)
stores the GTIN or EAN. Products without one are left out.

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
| `reviewerFirstName()` / `reviewerLastName()` | `customer.first_name` / `last_name` |
| `reviewOrderReference()` | `shopping_cart_display_id`, falling back to the primary key |
| `reviewCountryCode()` | `shippingAddress.country.alpha2` |
| `reviewCity()` | `shippingAddress.city` |
| `reviewOrderTotalInCents()` | `price_including_vat` |
| `reviewProducts()` | `items`, with the GTIN from `product.ni` |
| `reviewLocale()` | the app locale |
| `reviewEstimatedDeliveryDate()` | the config resolver, see above |

Order lines with no product, which is how the cart stores its shipping line,
are left out. So are lines with a quantity below one.

If your columns differ, adjust config rather than overriding methods:

```php
'order' => [
    'reference_column' => 'order_number',
    'gtin_column' => 'ean',
],
```

### Without marshmallow/cart

Nothing in the package requires it. Implement `ReviewableOrder` directly on
whatever model you do have:

```php
final class Lead extends Model implements ReviewableOrder
{
    public function reviewerEmail(): ?string
    {
        return $this->email;
    }

    public function reviewOrderReference(): string
    {
        return "LEAD-{$this->id}";
    }

    // ... the remaining methods may all return null
}
```

Every method except the order reference may return null. A provider decides for
itself which fields it needs and reports a skip when one is missing, rather
than the package guessing a value and pushing the failure to a remote API.

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

If you would rather handle the results yourself, the manager returns one
`InvitationResult` per active provider:

```php
foreach (Reviews::inviteAll($invitation) as $result) {
    if ($result->hasFailed()) {
        // $result->provider, $result->message, $result->status
    }
}
```

### Triggering from an order event

Off by default, because which [event](https://laravel.com/docs/events) means
"this order is really done" differs per site, and getting it wrong invites
people who never paid.

`marshmallow/cart` has no completed-order event: `OrderCreated` fires **before**
payment. `PaymentStatusPaid` from
[`marshmallow/payable`](https://packagist.org/packages/marshmallow/payable) is
the closer proxy, but it carries a `Payment` rather than an `Order`, so mapping
the event to an order is your call:

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

### Review links

For providers that publish a link you mail yourself, rather than mailing the
customer for you:

```php
foreach (Reviews::reviewLinks($invitation) as $provider => $url) {
    // drop $url into your own transactional email
}
```

## Blade components

Both [components](https://laravel.com/docs/blade#components) render absolutely
nothing, not even an empty wrapper, when there is nothing to render: consent
withheld, no active provider implements the capability, or the ones that do are
not configured.

```blade
<x-reviews::opt-in :order="$order" />
<x-reviews::badge />
```

`opt-in` accepts either a `ReviewableOrder` or a ready made `ReviewInvitation`.

## Extending

`Reviews::extend()` works exactly like Socialite's, in your
`AppServiceProvider::boot()`:

```php
use Illuminate\Contracts\Container\Container;
use Marshmallow\Reviews\Facades\Reviews;

Reviews::extend('feedbackcompany', fn (Container $app) => $app->make(FeedbackCompanyProvider::class));
```

Add `'feedbackcompany'` to `reviews.active` and it joins the fan-out. A custom
provider is a first class citizen: resolvable by name, matched by
`supporting()`, and eligible for the Blade components.

The same call **overrides a bundled provider**, because the manager consults
custom creators before its own:

```php
Reviews::extend('kiyoh', fn (Container $app) => new OurPatchedKiyohProvider(...));
```

### Writing one

Implement `Marshmallow\Reviews\Contracts\ReviewProvider` plus whichever
capability interfaces apply. Implement only what the provider can honour.

```php
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Support\ConfigValue;
use Marshmallow\Reviews\Support\Gate;

final class FeedbackCompanyProvider implements SendsInvitations
{
    public function __construct(
        private readonly Gate $gate,
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'feedbackcompany';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null;
    }

    public function invite(ReviewInvitation $invitation): InvitationResult
    {
        if (! $this->gate->maySend()) {
            return InvitationResult::skipped($this->name(), SkipReason::Disabled);
        }

        $token = $this->token();

        if ($token === null) {
            return InvitationResult::skipped($this->name(), SkipReason::NotConfigured);
        }

        if (! $invitation->hasEmail()) {
            return InvitationResult::skipped($this->name(), SkipReason::NoEmail);
        }

        $response = $this->http->withToken($token)->post('https://api.example.test/invitations', [
            'email' => $invitation->email,
            'reference' => $invitation->orderReference,
        ]);

        return $response->successful()
            ? InvitationResult::sent($this->name())
            : InvitationResult::failed($this->name(), 'Rejected the invitation.', $response->status());
    }

    private function token(): ?string
    {
        return ConfigValue::string($this->config->get('reviews.providers.feedbackcompany.api_token'));
    }
}
```

Check `Support\Gate` yourself. `maySend()` before anything that talks to an API
and `mayRender()` before anything that emits markup, because gating only the
fan-out leaves a direct `Reviews::driver('feedbackcompany')->invite()` posting
to a live API with the package switched off.

Report, do not throw. Unconfigured, duplicate, unreachable and rejected are all
outcomes rather than exceptions, which is what lets the queued job treat them
uniformly. Build every result through the factories: `InvitationResult::failed()`
redacts email addresses out of your message before anything writes it to a log.

To return raw markup from a rendering capability, wrap it in
`Marshmallow\Reviews\Support\Html`. Laravel's own `HtmlString` implements
`Htmlable` but not `Renderable`, so it does not satisfy the interface.

## Artisan commands

```bash
php artisan reviews:doctor
```

Read-only and safe on production. It reports the master switch, the default and
active providers, each provider's capabilities and whether it is configured,
the consent callback, the delivery date resolver, the bound events, and the
queue in use.

It exits non-zero on a problem that means reviews are not being collected:
Google active without a delivery date resolver, or a name in `reviews.active`
that cannot be resolved. A missing consent callback while a client side
provider is active is a warning, not a failure.

## Translations

The `reviews` namespace ships English and Dutch, used by `reviews:doctor`.

```bash
php artisan vendor:publish --tag=reviews-lang
```

Then edit `lang/vendor/reviews/{en,nl}/messages.php`.

## Testing your own application

`Reviews::fake()` swaps the manager for a recording double, so you can assert
on invitations without faking HTTP for every provider. It replaces the
container binding as well as the facade, so anything type hinting the manager
in its constructor gets the fake too.

```php
use Marshmallow\Reviews\Facades\Reviews;

it('invites the customer once the order is paid', function () {
    Reviews::fake();

    $order = Order::factory()->create();

    PaymentStatusPaid::dispatch($order->payment);

    Reviews::assertInvited($order->reviewOrderReference());
});
```

| Method | Purpose |
| --- | --- |
| `assertInvited($callback = null)` | At least one invitation matches. A string matches on the order reference. |
| `assertInvitedTimes($times, $callback = null)` | Exactly this many match. |
| `assertNotInvited($callback)` | None match. |
| `assertNothingInvited()` | Nothing was invited at all. |
| `invitations()` | The recorded `ReviewInvitation` objects, for direct inspection. |
| `respondWith($result)` | Script the `InvitationResult` every send returns. |
| `shouldFail($message, $status = null)` | Shorthand for scripting a failure, with an optional HTTP status. |

Simulate a provider outage without an HTTP layer:

```php
Reviews::fake()->shouldFail('Kiyoh is down');
```

The fake honours `reviews.enabled`, so a test asserting that a disabled package
invites nobody behaves the same as production.

Assertion messages name order references only, never an email address, a name
or a city.

## Privacy and consent

### What leaves your system

Put this in the client's data processing agreement.

| Provider | Data sent | To whom | Sent from |
| --- | --- | --- | --- |
| Kiyoh | email, first name, last name, city, order reference, product codes, locale | Kiyoh B.V. / Klantenvertellen, EU | your server |
| Google | merchant id, order id, **email**, delivery country, estimated delivery date, GTINs | Google LLC, US | **the visitor's browser** |

### Consent gates rendering, not sending

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
`InvitationResult` can only be constructed through factories that redact
addresses, so a provider cannot smuggle personal data into a message by
accident. The Kiyoh provider additionally scrubs addresses out of Kiyoh's own
error bodies, because a provider is free to quote back the address it rejected.

Successful invitations are not logged at all unless you ask:

```dotenv
REVIEWS_LOG_SUCCESSES=true
```

### Exceptions go to Sentry, not to the log

An unexpected exception from a provider is reported to
[Sentry](https://docs.sentry.io/platforms/php/guides/laravel/) in full, while
the log line stays redacted. A `Throwable` is not ours: an HTTP client that
echoes the request body would put a customer's email address into a log file
that gets shipped, rotated and read by anyone with server access. Sentry is
access controlled, does its own scrubbing, and is where someone actually looks.

This matters because the job never rethrows. Without it, a genuine bug in a
provider would be invisible: nothing raised, and nothing in the log but a class
name. It does nothing when Sentry is not installed, and never requires it.

```dotenv
REVIEWS_REPORT_EXCEPTIONS=false
```

## Migrating from an ad hoc integration

Version to version upgrades are in [UPGRADING.md](UPGRADING.md).

### From `marshmallow/reviews-kiyoh`

That package keeps working and is not deprecated until v2.0, when this one
absorbs the review reading side. Until then the two coexist: use this package
for invitations, keep the old one for the feed, the product sync and the Nova
resource.

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

If you have something posting to `/v1/invite/external` directly, delete it. The
one thing worth keeping is any bookkeeping you do around the send, such as
recording a notification against the customer. Move that to a listener on your
own event, or dispatch the job yourself and record alongside it.

### From an ad hoc Google snippet

Replace the pasted block with `<x-reviews::opt-in :order="$order" />` and move
the merchant id to `GOOGLE_MERCHANT_ID`. Then configure an estimated delivery
date resolver: a hardcoded snippet usually has a hardcoded date, and that is
the part that silently degrades.

## Roadmap

All six capability interfaces already ship in 1.0, so none of the following is
a breaking change. A provider gains methods; the contract does not change
shape.

| Version | Adds |
| --- | --- |
| 1.1 | [WebwinkelKeur](https://docs.webwinkelkeur.nl/api/invitations/add/) provider. Static credentials, a delay in days, a richer product payload. |
| 1.2 | [Trustpilot](https://developers.trustpilot.com/invitation-api/) provider. OAuth2 with refresh token storage. |
| 2.0 | `ImportsReviews` and `RespondsToReviews` on the bundled providers, a `reviews:import` command, and the deprecation of `marshmallow/reviews-kiyoh`. |
| 2.1 | [Trustoo](https://trustoo.nl) provider. Badge and review link only: Trustoo has no invitation API, invitations are sent by hand from their dashboard. |

## Testing this package

```bash
composer test          # phpstan, pint, type coverage, pest
composer test:unit     # pest only
composer analyse       # phpstan level 8
composer lint          # pint
composer refactor      # rector, run deliberately, not a CI gate
```

Built on [Pest](https://pestphp.com) and
[Orchestra Testbench](https://packages.tools/testbench). CI runs PHP 8.3, 8.4
and 8.5 against Laravel 12 and 13, at both `prefer-lowest` and `prefer-stable`.

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for what has changed recently, and
[UPGRADING.md](UPGRADING.md) for breaking changes.

## Contributing

Please see [CONTRIBUTING.md](.github/CONTRIBUTING.md) for details, including the
package guarantees a pull request must not break.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report
security vulnerabilities.

## Credits

- [Marshmallow](https://github.com/marshmallow-packages)

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
