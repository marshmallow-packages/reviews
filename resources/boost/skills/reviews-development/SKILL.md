---
name: reviews-development
description: >
  Wire review collection into Laravel webshops with the Reviews package:
  the ReviewableOrder contract, Kiyoh invitations, Google Customer Reviews
  opt-in and badge, consent gating, queued invitations, event triggers,
  custom providers and the doctor command.
license: MIT
metadata:
  author: Marshmallow
---

# Reviews

Use this skill when a Laravel application needs to integrate `marshmallow/reviews`.

## Primary Goal

- collect reviews for a webshop through whichever provider the client actually uses, with the smallest correct wiring
- keep the checkout untouched: an invitation is queued, never blocking, and never able to fail a page or a job

## Workflow

### 1. Inspect the app context

- confirm the app is a Laravel project on PHP 8.3+ and Laravel 12 or 13
- check whether `marshmallow/cart` is installed. It is a `suggest`, never a require, and it decides whether step 3 is a trait or a hand written class
- find the model that represents a completed sale. It is usually an Order, but it does not have to be: yardy.nl invites on a Lead
- ask which provider the client has an account with. Kiyoh and Klantenvertellen are one platform on two domains, Google Customer Reviews needs a Merchant Center account, and anything else needs a custom provider (step 8)
- check for a cookie consent package. Something has to answer `reviews.consent` before a Google module may render
- check whether `sentry/sentry-laravel` is installed. Swallowed provider exceptions go there, and nowhere else

### 2. Install and wire

```bash
composer require marshmallow/reviews
php artisan vendor:publish --tag=reviews-config
php artisan reviews:doctor
```

There is no install command and nothing is wired automatically. The default provider is `null`, so installing the package changes no behaviour at all until a provider is chosen on purpose.

Two keys decide what runs:

```php
// config/reviews.php
'default' => env('REVIEWS_PROVIDER', 'null'), // Reviews::driver() with no argument
'active' => ['kiyoh', 'google'],              // the fan-out, null means the default alone
```

`default` is Socialite's single driver lookup. `active` is the fan-out used by the queued job and both Blade components, and it exists because a webshop legitimately runs Google for its seller rating alongside Kiyoh for the invitation email and the on-site badge. Leave `active` at `null` for a single provider site.

Kiyoh:

```dotenv
REVIEWS_PROVIDER=kiyoh
KIYOH_BASE_URL=https://www.klantenvertellen.nl
KIYOH_API_TOKEN=your-publication-api-token
KIYOH_LOCATION_ID=1234567
KIYOH_LOCALE=nl
KIYOH_DELAY_DAYS=3
KIYOH_SKIP_WEEKENDS=true
KIYOH_PROFILE_URL=https://www.kiyoh.com/reviews/1234567/your-shop
```

The token is the Publication API token from the Kiyoh dashboard, sent as `X-Publication-Api-Token`.

Google:

```dotenv
REVIEWS_PROVIDER=google
GOOGLE_MERCHANT_ID=123456789
GOOGLE_OPT_IN_STYLE=CENTER_DIALOG
GOOGLE_BADGE_POSITION=BOTTOM_RIGHT
```

Set `REVIEWS_ENABLED=false` on staging. That is the master switch, and it is enforced inside the providers rather than only in the fan-out, so `Reviews::driver('kiyoh')->invite()` cannot reach the live API either. Inviting a real customer to review a test order is the failure mode it exists to prevent.

### 3. Implement ReviewableOrder

`Marshmallow\Reviews\Contracts\ReviewableOrder` is what the package needs to know about a sale. On `marshmallow/cart` the whole integration is one trait:

```php
use Marshmallow\Reviews\Concerns\DerivesReviewableOrder;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

class Order extends \Marshmallow\Ecommerce\Cart\Models\Order implements ReviewableOrder
{
    use DerivesReviewableOrder;
}
```

The trait reads column and relation names from `config('reviews.order.*')`, so a site whose schema differs adjusts config instead of overriding trait methods:

```php
'order' => [
    'reference_column' => 'order_number',
    'gtin_column' => 'ean',
    'customer_relation' => 'buyer',
],
```

Every derivation degrades to null rather than erroring, so the same trait works on a model with none of these relations. When the shape is genuinely different, implement the interface directly instead:

```php
use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Data\InvitationProduct;

class Lead extends Model implements ReviewableOrder
{
    public function reviewerEmail(): ?string { return $this->email; }
    public function reviewerFirstName(): ?string { return $this->first_name; }
    public function reviewerLastName(): ?string { return $this->last_name; }
    public function reviewOrderReference(): string { return (string) $this->id; }
    public function reviewLocale(): ?string { return 'nl'; }
    public function reviewCountryCode(): ?string { return 'NL'; }
    public function reviewCity(): ?string { return $this->city; }
    public function reviewOrderTotalInCents(): ?int { return null; }
    public function reviewEstimatedDeliveryDate(): ?CarbonImmutable { return null; }

    /** @return list<InvitationProduct> */
    public function reviewProducts(): array { return []; }
}
```

Only `reviewOrderReference()` may not return null: an invitation that cannot be traced back to a sale cannot be reconciled. Everything else is allowed to be absent, and each provider decides for itself whether the absence is a skip.

### 4. Supply the estimated delivery date

Google will not render its opt-in without one, and it also decides from it when the survey is sent. `marshmallow/cart` has no such concept, so it comes from the site. Without it the module renders nothing at all, on purpose: a snippet missing a required field fails silently in the visitor's browser, which is worse than rendering nothing visible.

Answer for all orders at once:

```php
// config/reviews.php
'estimated_delivery_date' => fn ($order) => now()->addWeekdays(3)->toImmutable(),
```

Or per order on the model, which wins over the config resolver:

```php
public function reviewEstimatedDeliveryDate(): ?CarbonImmutable
{
    return $this->shippingMethod?->estimated_delivery_at?->toImmutable();
}
```

`reviews:doctor` fails with a non-zero exit code when `google` is active and neither is present.

### 5. Place the Blade components

```blade
{{-- order confirmation page only --}}
<x-reviews::opt-in :order="$order" />

{{-- anywhere: footer, layout, product page --}}
<x-reviews::badge />
```

`:order` accepts a `ReviewableOrder` or a ready made `ReviewInvitation`. Both components decide on the finished markup rather than on the provider list, so they render literally nothing when consent is withheld, when no active provider renders, or when the ones that do decline for this one order. A layout can carry `<x-reviews::badge />` before any provider is configured.

The opt-in belongs on the order confirmation page and nowhere else. Google's module collects a per-order opt-in against a specific order id and email address, so a copy in a layout or on a product page is either a duplicate for the same order or a module with no order behind it.

The bundled Kiyoh badge view is a placeholder, because there is no generic Kiyoh badge derivable from a token and a location id. Publish and replace it with the account specific snippet from the dashboard:

```bash
php artisan vendor:publish --tag=reviews-views
```

### 6. Wire the consent callback

```php
// config/reviews.php
'consent' => fn (): bool => Cookiebot::hasConsent('marketing'),
```

Consent gates rendering and never sending. Posting an invitation to a provider's API from the server is a controller to processor transfer under the DPA, not a cookie placement. Google's opt-in module loads Google's JavaScript, sets Google's cookies and transfers the customer's email address to a US controller from the visitor's own browser, which is exactly what consent is for.

No callback means true, so installing the package never silently breaks a badge. `reviews:doctor` warns when a provider that renders client side is active without one. Providers check consent themselves as well as in the fan-out, so `Reviews::driver('google')->badge()` is not a way around the banner.

### 7. Trigger invitations

Dispatch the job directly from wherever the site already knows a sale completed. This is the recommended route:

```php
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;

SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order));

// Or target one provider instead of the fan-out
SendReviewInvitation::dispatch(ReviewInvitation::fromOrder($order), 'kiyoh');
```

The job never throws. A failing provider is logged at warning level and swallowed, because an invitation is the least important thing happening around a checkout and must never fail a job, retry a fan-out that half succeeded, or fill `failed_jobs` with noise nobody acts on.

The config driven listener is the alternative, and it defaults to off:

```php
'events' => [
    'enabled' => true,
    'listen' => [
        \Marshmallow\Payable\Events\PaymentStatusPaid::class,
    ],
    'resolve_order' => fn ($event) => $event->payment->payable,
],
```

It is off by default because which event means "this sale is really done" differs per site, and binding the wrong one invites people who never paid. `marshmallow/cart` has no completed-order event at all: `OrderCreated` fires before payment. `PaymentStatusPaid` from `marshmallow/payable` is the closer proxy, but it carries a `Payment` rather than an `Order`, which is what `resolve_order` is for. An event whose public `$order` property already holds a `ReviewableOrder` needs no resolver.

### 8. Write a custom provider

`Reviews::extend()` works exactly like Socialite's, from `AppServiceProvider::boot()`:

```php
use Illuminate\Contracts\Container\Container;
use Marshmallow\Reviews\Facades\Reviews;

Reviews::extend('feedbackcompany', fn (Container $app) => new FeedbackCompanyProvider(
    $app->make(\Marshmallow\Reviews\Support\Gate::class),
    $app->make(\Illuminate\Http\Client\Factory::class),
    $app->make(\Illuminate\Contracts\Config\Repository::class),
));
```

The provider implements the base contract plus only the capability interfaces it can honour:

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

        $response = $this->http->withToken($token)->post('https://api.example.test/invite', [
            'email' => $invitation->email,
            'reference' => $invitation->orderReference,
        ]);

        return $response->successful()
            ? InvitationResult::sent($this->name())
            : InvitationResult::failed($this->name(), 'Provider returned an error.', $response->status());
    }

    private function token(): ?string
    {
        return ConfigValue::string($this->config->get('reviews.providers.feedbackcompany.api_token'));
    }
}
```

Four rules a custom provider has to honour, or it is a bug:

- check `Gate::maySend()` before anything that talks to an API, and `Gate::mayRender()` before anything that emits markup. Gating only the fan-out leaves a direct `Reviews::driver()` call posting to a live API with the package switched off
- report, never throw. Unconfigured, duplicate, unreachable and rejected are all `InvitationResult` outcomes
- build results through the factories only. That is what keeps a customer's email address out of a log line
- implement a capability interface or do not implement it. There is no method returning null to mean "unsupported"

Add the name to `reviews.active` and it joins the fan-out, is matched by `supporting()`, and is eligible for the Blade components. The same call overrides a bundled provider, since the manager consults custom creators first:

```php
Reviews::extend('kiyoh', fn (Container $app) => new OurPatchedKiyohProvider(...));
```

A provider returning raw markup wraps it in `Marshmallow\Reviews\Support\Html`. `HtmlString` is not usable there: it implements `Htmlable`, and the capability interfaces return `Renderable`.

### 9. Verify

```bash
php artisan reviews:doctor
```

Read-only, safe on production. It reports the default provider, the active providers and their capabilities, and exits non-zero on the silent problems: Google active with no delivery date resolver, or an unresolvable name in `reviews.active`. A client side provider with no consent callback is a warning, not a failure, so the exit code stays zero.

In tests:

```php
use Marshmallow\Reviews\Facades\Reviews;

Reviews::fake();

// exercise the checkout

Reviews::assertInvited('ORDER-1234');
Reviews::assertInvitedTimes(1);
```

Available on the fake: `respondWith()`, `shouldFail()`, `invitations()`, and the assertions `assertInvited()`, `assertInvitedTimes()`, `assertNotInvited()`, `assertNothingInvited()`. `assertInvited()` and `assertNotInvited()` take an order reference string or a callback taking the `ReviewInvitation`.

Script a provider outage without an HTTP layer to fake:

```php
Reviews::fake()->shouldFail('Kiyoh is down');
```

`fake()` replaces the container binding as well as the facade instance, so the queued job and the view components get the double too. It records invitations and answers `inviteAll()` and `invite()` from memory; it resolves no drivers, so a test about one specific provider's request shape wants `Http::fake()` against the real manager instead.

## Rules, References, and Templates

Read before executing:

- the published `config/reviews.php`, which documents every key inline
- `README.md` in the package for the provider capability table, the Merchant Center steps and the migration notes

The contract a sale satisfies: `Marshmallow\Reviews\Contracts\ReviewableOrder`, with `reviewerEmail()`, `reviewerFirstName()`, `reviewerLastName()`, `reviewOrderReference()`, `reviewLocale()`, `reviewCountryCode()`, `reviewCity()`, `reviewEstimatedDeliveryDate()`, `reviewOrderTotalInCents()`, `reviewProducts()`. `Marshmallow\Reviews\Concerns\DerivesReviewableOrder` satisfies all ten against `marshmallow/cart`.

Capability interfaces, all extending `Marshmallow\Reviews\Contracts\ReviewProvider` (`name()`, `isConfigured()`):

- `SendsInvitations::invite(ReviewInvitation): InvitationResult`
- `RendersOptIn::optIn(ReviewInvitation): ?Renderable`
- `RendersBadge::badge(): ?Renderable`
- `ProvidesReviewLink::reviewLink(?ReviewInvitation): ?string`
- `ImportsReviews::reviews(ReviewQuery): iterable` and `summary(): ?ReviewSummary`
- `RespondsToReviews::respond(CollectedReview, ReviewResponse): ResponseResult`

Bundled providers: `kiyoh` sends, renders a badge and provides a link. `google` renders an opt-in and a badge and deliberately does not send, because Google Customer Reviews has no server side invitation API. `null` is the default and does nothing.

DTOs in `Marshmallow\Reviews\Data`: `ReviewInvitation` (with `fromOrder()`, `hasEmail()`, `fullName()`, `gtins()`, `context()`), `InvitationProduct`, `InvitationResult` (factories `sent()`, `skipped()`, `failed()`; predicates `wasSent()`, `wasSkipped()`, `hasFailed()`; `context()`), `CollectedReview`, `ReviewSummary`, `ReviewQuery`, `ReviewResponse`, `ResponseResult`. Enums: `InvitationOutcome` and `SkipReason` (`NotConfigured`, `ClientSideOnly`, `Duplicate`, `NoEmail`, `Disabled`, `MissingData`).

Facade methods on `Marshmallow\Reviews\Facades\Reviews`: `driver()`, `extend()`, `getDefaultDriver()`, `available()`, `active()`, `supporting()`, `inviteAll()`, `optInAll()`, `badgeAll()`, `reviewLinks()`, `activeNames()`, `enabled()`, `hasConsent()`, `fake()`. The assertion methods live on `Marshmallow\Reviews\Testing\ReviewsFake` rather than in the facade docblock, so hold the object `Reviews::fake()` returns when you need them under static analysis.

Config keys: `enabled`, `default`, `active`, `providers.kiyoh.*`, `providers.google.*`, `consent`, `estimated_delivery_date`, `order.*`, `events.enabled`, `events.listen`, `events.resolve_order`, `queue.*`, `http.*`, `log.channel`, `log.successes`, `log.report_exceptions`.

Env keys: `REVIEWS_ENABLED`, `REVIEWS_PROVIDER`, `REVIEWS_LISTEN_TO_EVENTS`, `REVIEWS_QUEUE_CONNECTION`, `REVIEWS_QUEUE`, `REVIEWS_QUEUE_TRIES`, `REVIEWS_HTTP_TIMEOUT`, `REVIEWS_HTTP_ATTEMPTS`, `REVIEWS_HTTP_RETRY_SLEEP`, `REVIEWS_LOG_CHANNEL`, `REVIEWS_LOG_SUCCESSES`, `REVIEWS_REPORT_EXCEPTIONS`, `KIYOH_BASE_URL`, `KIYOH_API_TOKEN`, `KIYOH_LOCATION_ID`, `KIYOH_LOCALE`, `KIYOH_DELAY_DAYS`, `KIYOH_SKIP_WEEKENDS`, `KIYOH_PROFILE_URL`, `GOOGLE_MERCHANT_ID`, `GOOGLE_OPT_IN_STYLE`, `GOOGLE_BADGE_POSITION`, `GOOGLE_REVIEWS_LANGUAGE`. There is no env key for `reviews.active`: it is an array in config.

Publish tags: `reviews-config`, `reviews-views`, `reviews-lang`, and `reviews` for all three. Command: `reviews:doctor`. Blade components: `<x-reviews::opt-in :order="$order" />` and `<x-reviews::badge />`.

## Examples

- A cart site on Kiyoh only: add the trait to `Order`, set `REVIEWS_PROVIDER=kiyoh` and the four Kiyoh keys, dispatch `SendReviewInvitation` where payment is confirmed, drop `<x-reviews::badge />` in the footer and replace the placeholder badge view with the dashboard snippet. No consent callback is needed, since nothing renders client side except the badge link.
- A cart site running both: set `'active' => ['kiyoh', 'google']`, add `GOOGLE_MERCHANT_ID`, add the `estimated_delivery_date` resolver, wire `consent` to the cookie banner, and place `<x-reviews::opt-in :order="$order" />` on the confirmation page. Kiyoh sends the email, Google renders the module, and `inviteAll()` reports Google as a `ClientSideOnly` skip rather than a failure.
- A site not on `marshmallow/cart` (yardy.nl): implement `ReviewableOrder` on the Lead model, dispatch the job from the place the lead is marked won, and leave `reviews.order.*` untouched since the trait is not in play.
- A client on a platform this package does not bundle: register it with `Reviews::extend()`, add a `providers.<name>` block to config, add the name to `reviews.active`, and confirm with `reviews:doctor` that it resolves and reports as configured.
- Debugging "no reviews are coming in": run `reviews:doctor` first. Google active with no delivery date is a red failure line, and it is the single most common cause of a site that looks correctly configured and collects nothing.

## Anti-patterns

- do not log an email address, a customer name or a city, ever. Log `$invitation->context()` and `$result->context()`, which are built to exclude all three. A log file gets shipped, rotated and read by anyone with server access
- do not catch a provider failure and rethrow it into the checkout. The job swallows on purpose. A review invitation is the least important thing happening around a payment, and a provider outage that fails a checkout is a far worse bug than a missing review
- do not put `<x-reviews::opt-in />` anywhere but the order confirmation page. It is per order, carries the order id and the email address, and a copy in a layout is either a duplicate opt-in or a module with no order behind it
- do not gate server side invitations behind cookie consent. That is a category error: posting to a provider's API is a processor transfer under the DPA, not a cookie placement, and gating it means a site that never sends anything to a provider it has a contract with
- do not add a method returning null to mean "this provider cannot do that". Implement the capability interface or do not implement it. Every caller asks `instanceof`, so an unimplemented interface is the answer, and a null returning stub makes the provider claim a capability it does not have
- do not type the parameter of an overridden `Manager::driver()`. `Illuminate\Support\Manager::driver($driver = null)` is untyped, and narrowing it to `?string` is a fatal incompatibility that makes the class unloadable. Use a `@param string|null $driver` docblock
- do not enable the event listener on `OrderCreated`. It fires before payment in `marshmallow/cart`, so it invites everyone who abandoned checkout. Use `PaymentStatusPaid` with a `resolve_order` callable, or dispatch the job yourself
- do not assume `marshmallow/cart` is installed. It is a `suggest`. Nothing in the package type hints a cart class, and a site without it implements `ReviewableOrder` directly
- do not put an unresolvable name in `reviews.active` and expect an exception. It is logged and skipped, because the components run on the order confirmation page and a 500 there is the worst possible failure. `reviews:doctor` is where a typo gets reported loudly
- do not use `Reviews::fake()` for a test about one provider's request shape. The fake resolves no drivers. Fake the HTTP layer against the real manager instead
