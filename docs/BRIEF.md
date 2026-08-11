# Unified review collection: phase 1 proposal

Working name in the brief: `marshmallow/laravel-review-collectors`.
Proposed name: **`marshmallow/reviews`** (see "Package name").

Date: 2026-08-11. Status: **approved and built**. This document is the
research and design record and is kept as written, including sections that
describe the pre-build state of the world. Current status and next steps live
in `docs/STATUS.md`; user facing documentation lives in `README.md`.

---

## 1. What we already have

### `marshmallow/reviews-kiyoh`

On Packagist, not cloned locally. Present in `topwebshop.nl` (`^1.2.3`) and
`yardy.nl` (`^1.0`) vendor directories. It is more capable than expected.

**What it does**

- Server side invitations: `KiyohInvite`, a fluent builder posting to
  `https://www.klantenvertellen.nl/v1/invite/external` with the
  `X-Publication-Api-Token` header. Supports `delay`, `ref_code`,
  `product_code[]`, `city`, `supplier`, `variable`, and validates `language`
  against a 29 locale allow list. Also has `delayIgnoreWeekend()`.
- Read side: JSON feed with cache, `KiyohProduct` and `KiyohReview` models plus
  migrations, `StoreReviewsInDatabase` command, product review sync, a Nova
  resource, a `KiyohProducts` trait, and API resources.
- Facades `Kiyoh`, `KiyohInvite`, `KiyohProduct`.

**Maturity**

| Signal | State |
| --- | --- |
| PHP constraint | `^7.4 \|\| ^8.0` |
| Stability | `minimum-stability: dev`, no `prefer-stable` |
| Tests | one file, `tests/Unit/ScheduleTest.php` |
| Tooling | `.php_cs.dist`, a php-syntax-checker workflow, no Pint, no PHPStan |
| Correctness | `KiyohInvite::invite()` reads `env()` at runtime, which returns null under `config:cache` |
| Naming | `KIYOH_HASH` at runtime vs `KIYOH_INVITE_HASH` in the config file, an inconsistency |
| Namespace | `Marshmallow\Reviews\Kiyoh\`, single provider by construction |

Verdict: the Kiyoh API knowledge in this package is real and worth harvesting
line by line. The package itself is not a viable base.

### `Packages/forks/kiyoh-php-api`

Fork of `mvdnbrk/kiyoh-php-api`. Read only feed client, `illuminate/collections`
`^8.0 || ^9.0`, upstream last touched years ago. Dead end, no invitation
support. Recommend archiving the fork once v2 lands.

### Ad hoc reimplementations in client code

`yardy.nl/app/Services/KiyohService.php` hand rolls the same invitation POST
that `marshmallow/reviews-kiyoh` already provides, in a project that requires
that package. It also implements a review response endpoint
(`PUT /v1/publication/review/response`) that the package does not have. This is
the exact duplication the new package exists to end, and the response endpoint
is a feature to harvest for v2.

### Everything else is a link, not an integration

Roughly fifteen sites reference `kiyoh` or `trustpilot` in
`config/marshmallow.php` and `app/Features.php`. All of them are footer badge
URLs and feature flags. No Google Customer Reviews, Trustpilot, Trustoo or
WebwinkelKeur integration exists anywhere in the fleet.

---

## 2. Our e-commerce stack

`marshmallow/cart` (namespace `Marshmallow\Ecommerce\Cart`, `php ^8.3`) is the
order layer. `marshmallow/payable` handles payment. `marshmallow/products`
holds products, `marshmallow/addressable` addresses,
`marshmallow/dataset-country` countries.

| Question | Answer |
| --- | --- |
| Order model | `Marshmallow\Ecommerce\Cart\Models\Order`, statuses `PENDING`, `CANCELED`, `COMPLETED`, with `markAsCompleted()` |
| Order line model | `Models\OrderItem` with `order()`, `product()`, `quantity`, `vatrate()`, `currency()` |
| Completed order event | **None.** Only `Events\OrderCreated(Order $order)` exists |
| Payment event | `Marshmallow\Payable\Events\PaymentStatusPaid(Payment $payment)`, the closest thing to "money received" |
| Customer email | `customers.email`, plus `first_name`, `last_name`, `company_name`, `phone_number` |
| Shipping address | `Order::shippingAddress()` via the `Addressable` trait, `addresses.city`, `addresses.country_id` |
| Country code | `countries.alpha2` (and `alpha3`), reached with `$order->shippingAddress?->country?->alpha2` |
| GTIN / EAN | `products.ni`. Nova labels the field "GTIN", help text says "This should be the EAN number of this product." |
| Estimated delivery date | **Does not exist.** Orders have `shipped_at`, `track_and_trace`, `shipping_method_id` only |
| Order reference | `orders.shopping_cart_display_id` is the human facing number |

Sites on `marshmallow/cart`: `logoanimatie.nl`, `milesenergy.nl`,
`oogvoororen.nl`, `topwebshop.nl`, `vdhsolar.nl`, `woodyou.care`.

Two consequences drive the design:

1. **There is no "order is done" event.** `OrderCreated` fires before payment.
   Inviting on it invites people who abandoned payment. The listener must
   therefore be configurable in which event it binds to, default to off, and
   ship with guidance to use `PaymentStatusPaid` where payment matters.
2. **Google's required `estimated_delivery_date` has no source in our data.**
   It has to come from the site.

---

## 3. Client inventory

| Project | Provider | How | Stack |
| --- | --- | --- | --- |
| `topwebshop.nl` | Kiyoh | `marshmallow/reviews-kiyoh ^1.2.3`. Scheduled `kiyoh:collect-reviews` daily, `kiyoh:update-products`, `kiyoh:fix-product-image`. Read heavy | `marshmallow/cart` + `payable`, Nova |
| `yardy.nl` | Kiyoh | `marshmallow/reviews-kiyoh ^1.0` for the feed, plus a hand written `KiyohService` for invites and review responses, plus AI review response agents and a Filament `ReviewInviteResource` | Not on cart, lead based, Filament |
| ~15 others | Trustpilot / Kiyoh | Footer badge link only, `config/marshmallow.php` + `app/Features.php` | mixed |

Driver priority follows directly: Kiyoh first, because it is the only provider
with paying clients today and two independent implementations to consolidate.

Migration path implications:

- `topwebshop.nl` is invite-light and read-heavy, so it moves at v2.
- `yardy.nl` is the immediate v1 win: its `KiyohService::sendReviewInvite()`
  collapses into the package. Its review response method becomes v2 input.
  Note `yardy.nl` is not on `marshmallow/cart`, so it exercises the
  "works without our e-commerce models" path, which is a useful constraint.

---

## 4. Versions and tooling, verified today

Verified against Packagist `repo.packagist.org/p2` and `php.net/releases`, not
from memory.

| Tool | Latest stable | bot-shield pins |
| --- | --- | --- |
| PHP | 8.5.9 | `^8.3` |
| laravel/framework | 13.25.0 (12.x still supported) | `illuminate/support ^12.0 \|\| ^13.0` |
| pestphp/pest | 5.1.0 | `^4.6` |
| laravel/pint | 1.30.5 | `^1.29` |
| larastan/larastan | 3.10.0 | `^3.9` |
| phpstan/phpstan | 2.2.8 | via larastan |
| rector/rector | 2.6.1 | **not present** |
| orchestra/testbench | 11.2.0 | `^10.0 \|\| ^11.0` |
| laravel/installer | 5.31.1 | n/a |
| laravel/pao | 1.1.4 | `^1.0` |

### bot-shield setup to mirror

- Namespace `Marshmallow\BotShield\`, PSR-4 on `src/`.
- Config `config/bot-shield.php`, merged as `bot-shield`. Short form
  everywhere, exactly as the brief requires.
- Publish tags: `['bot-shield', 'bot-shield-config']`, `-views`, `-lang`,
  `-migrations`. The bare package name tag publishes everything.
- View namespace `bot-shield`, lang namespace `bot-shield`, Blade component
  namespace registered via `callAfterResolving('blade.compiler', ...)`.
- Composer scripts: `lint`, `lint:check`, `analyse`, `test:types`,
  `test:unit`, and `test` running all four. `prepare` / `build` / `clear` via
  testbench. `post-autoload-dump` runs `@clear` then `@prepare`.
- PHPStan level **7** with `tmpDir: build/phpstan`, paths `src config database
  tests/Fixtures`, and a documented ignore for
  `larastan.noEnvCallsOutsideOfConfig` in `config/*`.
- Pint preset `laravel` with four rule overrides.
- CI matrix: PHP 8.3/8.4/8.5 x Laravel 12.*/13.* x prefer-lowest/prefer-stable,
  testbench 10 for L12 and 11 for L13, actions pinned by SHA, steps in order
  PHPStan, Pint, type coverage, tests.
- A `workbench/` app, `testbench.yaml`, `tests/ArchTest.php`, `tests/Pest.php`,
  `tests/TestCase.php`, `tests/Fixtures/`.
- `.agents/skills/` with package-scaffold, package-testing, package-release,
  package-compatibility, package-generate-skill, plus `AGENTS.md`,
  `.claudeignore`, `docs/BRIEF.md`.
- **`src/Testing/BotShieldFake.php`, `FakeCaptchaDriver.php`,
  `CollectingEventRecorder.php`.** A first class test double shipped with the
  package. We should do the same.

Two divergences to decide on, listed in open questions: Rector is absent from
bot-shield, and PHPStan is level 7 rather than max.

### The pattern to copy

`src/Captcha/CaptchaManager.php` plus `src/Contracts/CaptchaDriver.php` plus
`Drivers/{GoogleV2,GoogleV3,Null}Driver.php` is precisely the shape we want:
config driven `match` on driver name, a `customDriver()` escape hatch that
accepts any class name implementing the contract and throws a `RuntimeException`
naming the valid options otherwise, an `isActive()` that means "enabled AND
configured", and Blade components that render nothing when inactive.

---

## 5. Provider research

| Provider | Server side invite API | Auth | Client snippet | Personal data leaving our system |
| --- | --- | --- | --- | --- |
| **Kiyoh / Klantenvertellen** | Yes. `POST https://www.klantenvertellen.nl/v1/invite/external` (or `kiyoh.com`) | `X-Publication-Api-Token` header, static | Optional badge only | email, first name, last name, city, order ref, product codes, locale |
| **WebwinkelKeur** | Yes. `POST https://dashboard.webwinkelkeur.nl/api/1.0/invitations.json` | `id` + `code` as query params, static | Optional | email, customer name, phone numbers, order number, order total, product id/name/url/sku/gtin/brand/price/image |
| **Trustpilot** | Yes. `POST https://invitations-api.trustpilot.com/v1/private/business-units/{businessUnitId}/email-invitations` | **OAuth2 business user token**, refresh flow, needs storage | Optional | consumer name, email, reference id, template id, optional product review payload |
| **Google Customer Reviews** | **No** | n/a | **Required.** `renderOptIn` on the order confirmation page | merchant_id, order_id, email, delivery_country, estimated_delivery_date, optional product GTINs. Sent from the browser, plus Google cookies and JS |
| **Trustoo** (`trustoo.nl`) | **No** | n/a | Score widget, optional | none automatically. Invitations are sent by hand from their dashboard |

Provider specific notes:

- **Kiyoh** rejects a second invite to the same address within 30 days. That is
  a provider rule, not something to enforce locally. It must be treated as a
  non-fatal outcome so a retry does not look like a system failure.
- **Kiyoh** locale is a fixed 29 value allow list (`nl`, `en`, `fi-FI`,
  `es-ES`, `pt-BR`, ...). Not plain ISO-639-1. Needs mapping and validation.
- **WebwinkelKeur** takes auth in the query string and the payload in the body,
  and has a `delay` in days like Kiyoh. Its shape is close enough to Kiyoh that
  it is the ideal second driver to prove the contract.
- **Trustpilot** is the awkward one: OAuth2 with refresh tokens means the driver
  needs credential storage and a token cache, unlike the other two. It also
  requires a template id chosen in their dashboard. Whether API access needs
  partner approval is unverified.
- **Google** required fields are `merchant_id`, `order_id`, `email`,
  `delivery_country`, `estimated_delivery_date` (YYYY-MM-DD). `opt_in_style`
  and `products[].gtin` are optional, and GTINs are what unlock product level
  ratings rather than only seller ratings.

### Trustoo is `trustoo.nl`, and it is a third integration model

Confirmed with you: the Dutch `trustoo.nl`, not `trustoo.io` (which rebranded to
TrustWILL and is a Shopify product reviews app, irrelevant to us).

`trustoo.nl` has **no public developer portal and no invitation API.** Their own
guidance for businesses is: ask the customer directly, then send an email with a
review link **from the Trustoo dashboard**, or put a public review link in your
email signature. They also offer a styleable score widget for your own site, and
they can import existing reviews from Google and Facebook.

The API integrations they market, such as the 2Solar one, push **leads from
Trustoo into your CRM**. That is the opposite direction from what we need.

It is also worth noting Trustoo is a local-services directory (installers,
plumbers, estate agents, coaches) rather than a webshop review platform, so it
does not sit in the order-confirmation flow the way Kiyoh or WebwinkelKeur do.
It is closer to a fit for lead-based projects like `yardy.nl` than for our
webshops.

**Design consequence.** Trustoo cannot implement `SendsInvitations`. It is a
`RendersBadge` provider plus a `ProvidesReviewLink` one: not "we call their API"
and not "their JS renders on our page", but "they give us a URL and we mail it
ourselves". Kiyoh, WebwinkelKeur and Trustpilot all expose review links too, so
the capability is not Trustoo-only.

The interface is declared in v1 (section 8) even though no bundled provider
implements it until v2.1. Trustoo is the concrete evidence that Option B's
single fat contract would have been wrong: it would stub out four of five
methods.

---

## 6. Recommendation on the core question

**Build a new package.** Not extend, not nothing.

Reasoning from the findings above:

- Doing nothing is not viable: two projects already carry three
  implementations of the same Kiyoh invite between them, and every new webshop
  repeats the work.
- Extending `marshmallow/reviews-kiyoh` means either living inside the
  `Marshmallow\Reviews\Kiyoh\` namespace forever, which is wrong for a
  multi-provider package, or a namespace change, which is a breaking release
  anyway. Combined with PHP 7.4, `minimum-stability: dev`, one test file and no
  static analysis, extending buys nothing that a fresh package plus a careful
  read of the old source does not.
- A new package lets us set the modern baseline from commit one and deprecate
  the old one cleanly at v2 via Packagist `abandoned` + `replaced-by`.

The old package is not thrown away. Its Kiyoh request shape, locale list,
delay-ignoring-weekends helper and feed logic are the specification for our
drivers.

---

## 7. Package name

`review-collectors` is accurate for v1 but becomes wrong at v2, when the
package also reads reviews back. A collector that also displays reviews is a
reviews package.

**Recommended: `marshmallow/reviews`**, namespace `Marshmallow\Reviews\`.

- Matches the single-noun house style: `marshmallow/cart`, `products`,
  `payable`, `priceable`, `metadata`, `seoable`.
- Config `config/reviews.php`, publish tags `reviews`, `reviews-config`,
  `reviews-views`, `reviews-lang`, `reviews-migrations`. View namespace
  `reviews`. Components `<x-reviews::opt-in />`, `<x-reviews::badge />`.
- Deprecating `marshmallow/reviews-kiyoh` into `marshmallow/reviews` reads
  naturally: Kiyoh stops being a package and becomes a driver.

One wrinkle: during the v1 window both packages are installed, and their PSR-4
prefixes overlap (`Marshmallow\Reviews\` vs `Marshmallow\Reviews\Kiyoh\`).
Composer resolves the longest matching prefix first, so this works, but to keep
it unambiguous our drivers live under `Marshmallow\Reviews\Drivers\Kiyoh\`, not
`Marshmallow\Reviews\Kiyoh\`.

Conservative alternative if the overlap is unwelcome:
`marshmallow/review-collectors` with namespace `Marshmallow\ReviewCollectors\`,
config `review-collectors.php`. No collision, but the name ages badly.

---

## 8. Architecture

### Option A, recommended: a Socialite-shaped manager over capability interfaces

Two decisions, and they are independent of each other.

**Resolution is Socialite's**: extend Laravel's own `Illuminate\Support\Manager`,
get `driver()`, `extend()`, driver memoisation and the default-driver lookup for
free, and name the factory methods by convention.

**Capability is expressed by interfaces**: a thin `ReviewProvider` base contract
carries only identity and readiness, and each thing a provider can *do* is its
own interface. A provider implements exactly the ones it can honour.

```php
namespace Marshmallow\Reviews\Contracts;

interface ReviewProvider
{
    public function name(): string;

    /** Everything needed to do its job is present (keys, merchant id, ...). */
    public function isConfigured(): bool;
}

/** SEND: server side invitation over the provider's API. */
interface SendsInvitations extends ReviewProvider
{
    public function invite(ReviewInvitation $invitation): InvitationResult;
}

/** SHOW: client side opt-in module on the order confirmation page. */
interface RendersOptIn extends ReviewProvider
{
    /** Null when this provider has nothing to render. */
    public function optIn(ReviewInvitation $invitation): ?Renderable;
}

/** SHOW: score badge or seal, anywhere on the site. */
interface RendersBadge extends ReviewProvider
{
    public function badge(): ?Renderable;
}

/** IMPORT: pull reviews back out of the provider. */
interface ImportsReviews extends ReviewProvider
{
    /** Lazy so a provider can paginate without holding everything in memory.
     *  @return iterable<int, CollectedReview> */
    public function reviews(ReviewQuery $query): iterable;

    public function summary(): ?ReviewSummary;
}

/** IMPORT: publish a reply to an imported review. */
interface RespondsToReviews extends ReviewProvider
{
    public function respond(CollectedReview $review, ReviewResponse $response): ResponseResult;
}

/** LINK: hand us a URL we mail ourselves (Trustoo, and the others too). */
interface ProvidesReviewLink extends ReviewProvider
{
    public function reviewLink(?ReviewInvitation $invitation = null): ?string;
}
```

### The manager

```php
namespace Marshmallow\Reviews;

use Illuminate\Support\Manager;

final class ReviewManager extends Manager
{
    public function getDefaultDriver(): string;          // config('reviews.default')

    protected function createKiyohDriver(): KiyohProvider;
    protected function createGoogleDriver(): GoogleProvider;
    protected function createNullDriver(): NullProvider;
}
```

That is all `Manager` needs. Everything Socialite gives you comes with it:

```php
Reviews::driver('kiyoh')->invite($invitation);
Reviews::driver('google')->optIn($invitation);
Reviews::invite($invitation);                     // default driver, via __call
```

**Custom providers and overrides**, registered in a site's
`AppServiceProvider::boot()`, exactly as Socialite does it:

```php
Reviews::extend('feedbackcompany', fn ($app) => new FeedbackCompanyProvider(
    $app['config']['reviews.providers.feedbackcompany'],
));

// Overriding a built-in is the same call. Manager checks $customCreators
// before createKiyohDriver(), so this wins with no subclassing:
Reviews::extend('kiyoh', fn ($app) => new OurPatchedKiyohProvider(...));
```

An unknown driver throws `InvalidArgumentException("Driver [foo] not
supported.")` from `Manager::createDriver()`. We catch and rethrow it as
`UnknownReviewProvider` so the message can name the registered providers, the
way `CaptchaManager::customDriver()` does.

### One addition to Socialite's shape: a fan-out façade

Socialite is strictly one-driver-at-a-time because you only ever log a user in
through one. Reviews are not like that. Running Google Customer Reviews for
Google seller ratings **alongside** Kiyoh for the on-site badge and the
invitation email is a normal Dutch webshop setup, not an edge case.

So on top of the Socialite core, the manager exposes a small fan-out layer over
a configured list, which drives the job and the Blade components:

```php
/** @return list<ReviewProvider> */
public function enabled(): array;                        // config('reviews.enabled')

/** Every enabled provider implementing SendsInvitations.
 *  @return list<InvitationResult> */
public function inviteAll(ReviewInvitation $invitation): array;

/** Concatenated output of every enabled provider implementing RendersOptIn.
 *  Empty string when consent is withheld or nothing renders. */
public function optInAll(ReviewInvitation $invitation): string;

public function badgeAll(): string;

/** @return list<ReviewProvider> */
public function supporting(string $capability): array;   // ::class of the interface
```

`enabled()` defaults to `[getDefaultDriver()]`, so a site that never touches it
behaves exactly like Socialite.

**Why this is the recommendation.** No integration model is bolted onto another:
"can this provider send?" is `instanceof SendsInvitations`, a type question, not
a runtime null check. Adding WebwinkelKeur is one class plus one
`createWebwinkelkeurDriver()` method, with no contract change and therefore no
breaking release. Adding a whole new *kind* of capability later (the Trustoo
review-link case) is a new interface that leaves every existing provider
untouched. And the resolution half is a Laravel core class our team already
knows from Socialite, Cache, Queue and Mail.

**Cost.** Six interfaces instead of one, and a consumer holding a provider
directly must check capability before calling. `supporting()` and the fan-out
methods exist so that in practice almost nobody does.

### Option B: one fat `ReviewProvider` interface

`sendInvite()`, `optInView()`, `badgeView()`, `import()` on one interface,
providers return null for what they do not support.

Simpler to consume and to teach. But the Google provider would have to implement
`sendInvite()` as either a silent no-op or a thrown exception, and a silent
no-op is the worst option: a site switches to Google, invitations quietly stop,
and nothing surfaces it. Google also has no import API at all, so a quarter of
the interface is dead on it. This is precisely the "bolting the API case onto a
snippet oriented design" the brief warns against, inverted. Trustoo makes it
worse still: it can only do badge and link, so it would stub out four of five
methods. **Rejected.**

### Option C: two independent contracts, no manager

`ReviewInvitationSender` and `ReviewWidgetRenderer` bound separately in the
container, resolved independently. Cleanest separation and it naturally
supports mixing providers.

But it gives up `extend()`, the driver memoisation, the "driver not supported"
error, the single config key, and the fake, and it pushes the mixing decision
onto every site whether or not they need it. It also throws away the Socialite
familiarity that is the point of Option A. Option A gets the same mixing ability
from `enabled()` while keeping one resolution point. **Rejected, but it informed
A's fan-out layer.**

---

## 9. Proposed contract, with signatures

### Data objects

```php
namespace Marshmallow\Reviews\Data;

final readonly class ReviewInvitation
{
    /** @param list<InvitationProduct> $products
     *  @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $email,
        public string $orderReference,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $locale = null,              // ISO-639-1, mapped per driver
        public ?string $countryCode = null,         // ISO-3166-1 alpha-2
        public ?string $city = null,
        public ?CarbonImmutable $estimatedDeliveryDate = null,
        public ?int $delayInDays = null,
        public ?int $orderTotalInCents = null,
        public array $products = [],
        public array $metadata = [],
    ) {}

    public static function fromOrder(ReviewableOrder $order): self;
}

final readonly class InvitationProduct
{
    public function __construct(
        public string $identifier,      // internal id or sku
        public ?string $gtin = null,    // products.ni
        public ?string $name = null,
        public ?string $url = null,
        public ?string $imageUrl = null,
        public ?int $priceInCents = null,
    ) {}
}

final readonly class InvitationResult
{
    public static function sent(string $provider, ?string $reference = null): self;
    public static function skipped(string $provider, SkipReason $reason): self;
    public static function failed(string $provider, string $message, ?int $status = null): self;

    public function wasSent(): bool;
    public function wasSkipped(): bool;
    public function failed(): bool;

    /** Safe for logging: contains no email address or customer name. */
    public function context(): array;
}

enum SkipReason: string
{
    case NotConfigured    = 'not_configured';
    case ClientSideOnly   = 'client_side_only';   // Google: renders, does not send
    case Duplicate        = 'duplicate';          // Kiyoh 30 day rule
    case NoEmail          = 'no_email';
    case Disabled         = 'disabled';
}
```

`ReviewInvitation` is `readonly` and `Queueable`-safe: it holds scalars and a
list of scalars, so it serialises onto the queue without `SerializesModels` and
without dragging an Eloquent model through the payload.

### Import objects

Defined in v1 alongside the `ImportsReviews` contract, so a custom provider can
implement importing on day one even though no bundled provider does until v2.

```php
namespace Marshmallow\Reviews\Data;

final readonly class CollectedReview
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public float $rating,
        public float $ratingScale = 10.0,     // Kiyoh is /10, Google and Trustpilot /5
        public ?string $authorName = null,
        public ?string $city = null,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $locale = null,
        public ?CarbonImmutable $reviewedAt = null,
        public bool $verified = false,
        public ?string $productIdentifier = null,
        public ?string $gtin = null,
        public ?string $response = null,
        public ?CarbonImmutable $respondedAt = null,
        public array $raw = [],
    ) {}

    /** Rating normalised to 0..1, so mixed-provider averages are meaningful. */
    public function normalisedRating(): float;
}

final readonly class ReviewQuery
{
    public function __construct(
        public ?int $limit = null,
        public ?CarbonImmutable $since = null,
        public ?string $locale = null,
        public ?string $productIdentifier = null,
    ) {}
}

final readonly class ReviewSummary
{
    public function __construct(
        public string $provider,
        public float $average,
        public float $scale,
        public int $count,
        public ?CarbonImmutable $fetchedAt = null,
    ) {}
}

final readonly class ReviewResponse
{
    public function __construct(
        public string $body,
        public bool $public = true,
        public bool $notifyReviewer = false,
    ) {}
}
```

`reviews()` returns `iterable` rather than an array specifically so a provider
can be a generator: Kiyoh's feed is capped per request and Trustpilot paginates,
and neither should force the whole history into memory.

### Capability matrix

| Provider | Send | Show opt-in | Show badge | Import | Respond | Link | Lands |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | --- |
| Kiyoh | yes | - | yes | yes | yes | yes | send+badge v1, rest v2 |
| Google Customer Reviews | - | yes | yes | - | - | - | v1 |
| null | yes | yes | yes | yes | yes | yes | v1 |
| WebwinkelKeur | yes | - | yes | yes | ? | yes | v1.1 |
| Trustpilot | yes | - | yes | yes | yes | yes | v1.2 |
| Trustoo | - | - | yes | - | - | yes | v2.1 |

The null provider implements every interface deliberately: it is what makes
"the contract is satisfiable" a compile-time fact and gives every capability a
test subject from v1.

### Order integration

```php
namespace Marshmallow\Reviews\Contracts;

interface ReviewableOrder
{
    public function reviewerEmail(): ?string;
    public function reviewerFirstName(): ?string;
    public function reviewerLastName(): ?string;
    public function reviewOrderReference(): string;
    public function reviewLocale(): ?string;
    public function reviewCountryCode(): ?string;       // alpha-2
    public function reviewCity(): ?string;
    public function reviewEstimatedDeliveryDate(): ?CarbonImmutable;
    public function reviewOrderTotalInCents(): ?int;

    /** @return list<InvitationProduct> */
    public function reviewProducts(): array;
}
```

```php
namespace Marshmallow\Reviews\Concerns;

/** Derives everything above from marshmallow/cart conventions.
 *  Every method is individually overridable. */
trait DerivesReviewableOrder { /* ... */ }
```

Derivations against our stack:

| Method | Derived from |
| --- | --- |
| `reviewerEmail()` | `$this->customer?->email` |
| `reviewerFirstName()` / `LastName()` | `$this->customer?->first_name` / `last_name` |
| `reviewOrderReference()` | `$this->shopping_cart_display_id ?? $this->getKey()` |
| `reviewLocale()` | `app()->getLocale()` |
| `reviewCountryCode()` | `$this->shippingAddress?->country?->alpha2` |
| `reviewCity()` | `$this->shippingAddress?->city` |
| `reviewOrderTotalInCents()` | `$this->price_including_vat` |
| `reviewProducts()` | `$this->items` mapped, `gtin` from `product->ni`, `identifier` from `product_id` |
| `reviewEstimatedDeliveryDate()` | the config resolver below |

Column names come from a `reviews.order` config block, so a site whose Order
does not match cart conventions adjusts config instead of overriding methods.
Nothing in the trait hard-requires `marshmallow/cart`: it uses optional chaining
throughout and degrades to null, which is what `yardy.nl` needs.

### Config sketch

```php
// config/reviews.php
return [
    // Socialite-style default driver, used by Reviews::invite() and friends.
    'default' => env('REVIEWS_PROVIDER', 'null'),

    // Providers that participate in the fan-out (job, Blade components).
    // Defaults to [default] when omitted.
    'enabled' => ['kiyoh', 'google'],

    // Per-provider credentials. Custom providers registered with
    // Reviews::extend() read their own key from here too.
    'providers' => [
        'kiyoh' => [
            'base_url'    => env('KIYOH_BASE_URL', 'https://www.klantenvertellen.nl'),
            'api_token'   => env('KIYOH_API_TOKEN'),
            'location_id' => env('KIYOH_LOCATION_ID'),
            'locale'      => env('KIYOH_LOCALE', 'nl'),
            'delay_days'  => env('KIYOH_DELAY_DAYS', 3),
            'skip_weekends' => true,
        ],
        'google' => [
            'merchant_id'  => env('GOOGLE_MERCHANT_ID'),
            'opt_in_style' => env('GOOGLE_OPT_IN_STYLE', 'CENTER_DIALOG'),
            'badge_position' => env('GOOGLE_BADGE_POSITION', 'BOTTOM_RIGHT'),
        ],
    ],
    // ...
];
```

`Reviews::extend('feedbackcompany', ...)` needs no config change to work; it
just conventionally reads `reviews.providers.feedbackcompany`, and adding
`'feedbackcompany'` to `enabled` puts it in the fan-out. A custom provider is a
first class citizen, not a bolt-on.

### Estimated delivery date

```php
// config/reviews.php
'estimated_delivery_date' => null,   // null | callable(ReviewableOrder): ?CarbonImmutable
```

The site owns this, because only the site knows its lead times. A site can also
implement `reviewEstimatedDeliveryDate()` on its Order model directly, which
wins over the config. When neither is present the Google driver refuses to
render and a `reviews:doctor` command reports it, rather than guessing a date
and silently mistiming every survey.

### Job and listener

```php
namespace Marshmallow\Reviews\Jobs;

final class SendReviewInvitation implements ShouldQueue
{
    public function __construct(
        public readonly ReviewInvitation $invitation,
        public readonly ?string $provider = null,     // null = every enabled provider
    ) {}

    public function handle(ReviewManager $manager): void;
}
```

Never throws. Driver exceptions and non-2xx responses are caught, recorded as
`InvitationResult::failed()`, and logged at `warning` through the configured
channel with `InvitationResult::context()`, which excludes the email address
and customer name. Queue, connection, `tries` and `backoff` come from config.
HTTP-level retries happen inside the driver via `Http::retry()`, so the job does
not re-run the whole fan-out for one flaky provider.

```php
namespace Marshmallow\Reviews\Listeners;

final class SendInvitationForOrderEvent
{
    public function handle(object $event): void;
}
```

Registered only when `reviews.events.enabled` is true. Which events it binds to
is config, and how an event maps to a `ReviewableOrder` is a config resolver,
because `OrderCreated` carries an Order while `PaymentStatusPaid` carries a
Payment:

```php
'events' => [
    'enabled' => env('REVIEWS_LISTEN_TO_EVENTS', false),
    'listen'  => [
        \Marshmallow\Payable\Events\PaymentStatusPaid::class,
    ],
    'resolve_order' => null,   // callable(object $event): ?ReviewableOrder
],
```

Default is **off**, and the documented default event is `PaymentStatusPaid`
rather than `OrderCreated`, because `OrderCreated` fires before payment and
would invite customers who never completed checkout.

### Blade components and consent

```blade
<x-reviews::opt-in :order="$order" />
<x-reviews::badge />
```

Both render nothing when the consent callback returns false, when no enabled
provider implements the relevant interface, or when the providers that do are
not configured.

```php
'consent' => null,   // null | callable(): bool
```

Consent gates **client side rendering only**. Sending a server side invitation
is a controller-to-processor transfer under the DPA, not a cookie placement, so
it is not gated by a cookie banner. Google's opt-in module loads Google JS and
sets cookies in the visitor's browser, so it genuinely is. Making that
distinction explicit in the design is the point of having one callback that
applies to one half.

### Test double

```php
Reviews::fake();
Reviews::assertInvited(fn (ReviewInvitation $i) => $i->orderReference === '1234');
Reviews::assertInvitedTimes(1);
Reviews::assertNothingInvited();
```

Mirrors `BotShieldFake`. Ships in `src/Testing/`, so client projects can assert
against invitations without faking HTTP.

---

## 10. How it plugs in without clients rewriting code

For a site on `marshmallow/cart`:

```php
use Marshmallow\Reviews\Concerns\DerivesReviewableOrder;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

class Order extends \Marshmallow\Ecommerce\Cart\Models\Order implements ReviewableOrder
{
    use DerivesReviewableOrder;
}
```

That is the whole integration. Add the merchant id or API token to `.env`, set
`reviews.enabled`, drop `<x-reviews::opt-in :order="$order" />` on the
confirmation view if a snippet driver is enabled, and either flip
`reviews.events.enabled` on or dispatch `SendReviewInvitation` where the site
already knows an order is done.

For `yardy.nl`, which is not on cart: implement `ReviewableOrder` on `Lead`
directly, or build a `ReviewInvitation` by hand. `KiyohService::sendReviewInvite()`
becomes `Reviews::invite(ReviewInvitation::fromOrder($lead))`.

Nothing in the package requires `marshmallow/cart`. It is a `suggest`, not a
`require`, and the trait degrades to nulls when the relations are absent.

---

## 11. Phasing

**The whole contract is declared in v1.** All six capability interfaces and all
DTOs ship in 1.0, so a site or a custom provider can implement send, show,
import, respond or link from day one. What phases is which **bundled providers**
implement which capability, not the contract itself. That is the point of the
Socialite shape: `Reviews::extend()` means a client is never blocked waiting for
us to ship a provider.

**v1.0**: send and show.

Contracts (all six), DTOs (invitation and import), `ReviewManager` on
`Illuminate\Support\Manager`, `enabled()` fan-out, `ReviewableOrder` + trait,
queued job, optional listener, Blade opt-in and badge, consent callback, fake,
config, `reviews:doctor`, README, full Pest suite, CI matrix.
Providers: **Kiyoh** (`SendsInvitations` + `RendersBadge`), **Google Customer
Reviews** (`RendersOptIn` + `RendersBadge`), **null** (every interface, does
nothing). One real API provider and one real snippet provider, so both models
are exercised in production from day one.

**v1.1**: WebwinkelKeur provider. Static credentials, delay in days, richer
product payload. Its purpose is to prove a third API provider lands as one class
plus one `createWebwinkelkeurDriver()` method, with zero contract change.

**v1.2**: Trustpilot provider. OAuth2 with refresh token storage and a token
cache. The first provider whose auth does not fit the static-credential shape,
which is the real stress test of the resolution layer.

**v2.0**: import, respond, and deprecation. `ImportsReviews` and
`RespondsToReviews` implemented on Kiyoh, WebwinkelKeur and Trustpilot. Feed
fetch with cache, review and product models plus migrations, a `reviews:import`
command, review responses (harvested from `yardy.nl`), and a data migration from
`kiyoh_products` / `kiyoh_reviews`. `marshmallow/reviews-kiyoh` marked
`abandoned` on Packagist with `replaced-by` pointing here. `topwebshop.nl` and
`yardy.nl` migrate. The `kiyoh-php-api` fork is archived.

**v2.1**: `ProvidesReviewLink` capability plus a **Trustoo** driver
(`RendersBadge` + `ProvidesReviewLink`), and review links backfilled onto the
Kiyoh, WebwinkelKeur and Trustpilot drivers. Only worth building if a client
actually asks for Trustoo, since nothing in the fleet uses it today.

Not in scope at any phase: an admin UI. The package stays headless like
bot-shield, and sites keep their own Nova or Filament resources.

---

## 12. Risks and open questions

**Design and data**

1. **`estimated_delivery_date` has no source.** If a site supplies a wrong date,
   Google surveys at the wrong time and response rates drop silently. Mitigated
   by refusing to render without one and surfacing it in `reviews:doctor`, but
   the underlying gap is real. Worth considering a proper delivery-estimate
   concept in `marshmallow/cart` eventually, which is out of scope here.
2. **No paid/completed order event in `marshmallow/cart`.** We work around it
   with a configurable event plus a resolver, but the clean fix is an
   `OrderCompleted` event upstream. Flagging, not fixing.
3. **Namespace overlap** between `Marshmallow\Reviews\` and the old
   `Marshmallow\Reviews\Kiyoh\` during the v1 coexistence window. Legal in
   Composer, mitigated by putting drivers under `Drivers\`, but it is a
   readability cost until v2.
4. **Kiyoh's 30 day per-email rule** makes legitimate retries look like
   failures. Handled as `SkipReason::Duplicate` rather than a failure, but the
   provider's error payload has to be parsed to tell the two apart, and that
   parsing is undocumented.
5. **`reviews-kiyoh` reads `env()` at runtime** and disagrees with its own
   config over `KIYOH_HASH` vs `KIYOH_INVITE_HASH`. Any site migrating needs
   its `.env` checked against both names. This is a Forge-side change on
   production for `topwebshop.nl` and `yardy.nl`, not just a repo change.

**Provider**

6. **Trustoo has no invitation API and no client using it.** Confirmed as
   `trustoo.nl`. It can only ever be a badge plus a review link we mail
   ourselves, so it cannot be automated off an order event the way the brief
   assumes. Before building it, we need to know which client wants it and
   whether "show the Trustoo score" is the actual requirement, which needs no
   driver at all beyond a badge.
7. **Trustpilot API access may require partner approval.** Unverified. Could
   block v1.2 on a commercial conversation rather than code.
8. **Google Customer Reviews has no API, by design.** If Google changes the
   opt-in module we have no server-side fallback. This is inherent to the
   provider, not to our design.

**GDPR and cookie consent**

9. Per-driver data flow needs documenting for each client's DPA. Draft:

   | Driver | Data sent | To whom | Where |
   | --- | --- | --- | --- |
   | Kiyoh | email, first name, last name, city, order reference, product codes, locale | Kiyoh B.V. / Klantenvertellen | EU |
   | WebwinkelKeur | email, name, phone numbers, order number, order total, product id/name/url/sku/gtin/brand/price/image | WebwinkelKeur B.V. | EU |
   | Trustpilot | consumer name, email, reference id | Trustpilot A/S | EU/DK, US sub-processors |
   | Google Customer Reviews | merchant id, order id, email, delivery country, estimated delivery date, GTINs, **sent from the visitor's browser** | Google LLC | US, third country transfer |

10. **Google is the consent-sensitive one.** It loads Google JS, sets cookies,
    and transfers the customer's email to a US controller from the browser. It
    must sit behind the consent callback and behind a "marketing" or
    "functional" category decision the client's cookie policy makes. The other
    drivers are server-to-server processor transfers and do not need a banner,
    though they do need to be in the privacy statement.
11. **Never log email addresses.** Enforced by `InvitationResult::context()`
    excluding them and by an arch test asserting no driver passes an email into
    a log call. Info-level logging of invitations is off by default.

**Tooling decisions, resolved 2026-08-11**

12. **Rector: in, as a manual script.** `composer refactor` and
    `composer refactor:check` with a `rector.php`. **Not** part of the `test`
    chain and **not** a CI gate, so a Rector/Pint disagreement never blocks a
    PR. Run deliberately.
13. **PHPStan: level 8 is the committed floor**, plus 100% Pest type coverage
    as bot-shield does. I attempt `max` and keep it only if it lands without a
    wall of ignores. If it does not, the package stays at 8 and I report why
    rather than papering over it with a baseline.
14. **`php ^8.3`, `illuminate/support ^12.0 || ^13.0`.** Identical to
    bot-shield. Every site on `marshmallow/cart` already qualifies. CI matrix
    PHP 8.3/8.4/8.5 x Laravel 12.*/13.* x prefer-lowest/prefer-stable.
15. **Name: `marshmallow/reviews`**, namespace `Marshmallow\Reviews\`, config
    `config/reviews.php`, publish tags `reviews`, `reviews-config`,
    `reviews-views`, `reviews-lang`, `reviews-migrations`, view namespace
    `reviews`, components `<x-reviews::opt-in />` and `<x-reviews::badge />`.
    Bundled providers live under `Marshmallow\Reviews\Providers\` to keep the
    PSR-4 overlap with the old `Marshmallow\Reviews\Kiyoh\` unambiguous during
    the v1 window.

---

## 13. Summary of what I am asking you to approve

- New package, named **`marshmallow/reviews`**, namespace `Marshmallow\Reviews\`.
- **Option A** architecture: Socialite's shape. `ReviewManager extends
  Illuminate\Support\Manager` for resolution, `Reviews::driver('kiyoh')`,
  `Reviews::extend()` for custom providers and for overriding bundled ones. On
  top of it, a thin `ReviewProvider` contract plus six capability interfaces
  covering **send, show, import, respond, link**, and an `enabled()` fan-out so
  a site can run Google and Kiyoh at once.
- The **full contract ships in v1**; only provider implementations phase.
- v1 drivers: **Kiyoh, Google Customer Reviews, null**.
- Estimated delivery date resolved by a **config callback** supplied per site.
- Event listener **off by default**, bound to `PaymentStatusPaid` with a
  configurable order resolver.
- Read side and `reviews-kiyoh` deprecation deferred to **v2**. Trustoo deferred
  to **v2.1** as a badge and review-link driver, since it has no invitation API.
- **No admin UI** in v1.
- Tooling per section 12, items 12 to 15: Rector as a manual script only,
  PHPStan level 8 as the floor, `php ^8.3` with Laravel 12 and 13, name
  `marshmallow/reviews`. **All four resolved 2026-08-11.**

### Still needed before phase 2 starts

1. **Your go-ahead on this document as a whole.**
2. **The GitHub repo.** bot-shield lives at
   `github.com/marshmallow-packages/bot-shield`. Confirm
   `marshmallow-packages/reviews` and whether I create it with `gh` or you do.
   Nothing gets pushed without asking either way.
