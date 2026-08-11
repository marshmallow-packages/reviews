# Reviews

Unified review collection for Laravel webshops. One contract per capability,
one provider per platform, resolved the way Socialite resolves social logins.

Read `docs/STATUS.md` first when resuming work: it records where things stand,
the open decisions, and what happens next. Read `docs/BRIEF.md` for why the
package is shaped the way it is: it is the research and design record,
including the provider API findings and the decisions that were taken
deliberately.

Sentry and Linear are deliberately undeclared for this repo. Packages carry no
`## Tracking` block (bot-shield has none either); errors surface in the
consuming application's Sentry. Never guess a Sentry or Linear project for
this package, ask.

## Architecture in one paragraph

`Reviews` extends `Illuminate\Support\Manager`, so `driver()`, `extend()` and
driver memoisation come from Laravel. Providers implement a thin
`Contracts\ReviewProvider` plus whichever capability interfaces they can
honour: `SendsInvitations`, `RendersOptIn`, `RendersBadge`, `ImportsReviews`,
`RespondsToReviews`, `ProvidesReviewLink`. On top of the Socialite core sits a
fan-out over `config('reviews.active')`, because a webshop legitimately runs
more than one provider at once.

## Rules that are not negotiable

- **Never log an email address, a customer name or a city.** Every log line
  goes through a `context()` method built to exclude them.
  `InvitationResult` can only be built through its factories so a provider
  cannot smuggle personal data into a message. `KiyohProvider` additionally
  scrubs addresses out of the provider's own error bodies. If you add logging,
  it goes through `context()`.
- **The queued job never throws.** A review invitation is the least important
  thing happening around a checkout. Failures are logged at warning and
  swallowed.
- **Swallowed exceptions go to Sentry, never to the log.** Because the job
  never rethrows, `Support\ExceptionReporter` is the only thing standing
  between a provider bug and total invisibility. Any new place that catches a
  `Throwable` and carries on must report it there too. The log keeps the
  redacted line; Sentry gets the exception.
- **A provider reports, it does not throw.** Unconfigured, duplicate,
  unreachable and rejected are all `InvitationResult` outcomes, not exceptions.
- **Capability is a type, not a null check.** If a provider cannot do
  something, it does not implement that interface. Do not add a method that
  returns null to mean "unsupported".
- **Consent gates rendering, never sending.** Server side invitations are a
  processor transfer under the DPA. Client side modules set cookies.
- **`Support\Gate` is checked inside providers, not only in the fan-out.**
  `maySend()` for anything that talks to an API, `mayRender()` for anything
  that emits markup. Gating only `Reviews::active()` leaves
  `Reviews::driver('kiyoh')->invite()` posting to a live API with the package
  switched off, which on staging means mailing a real customer about a test
  order. A new provider that skips this guard is a bug.
- **Nothing user facing may throw over configuration.** A typo in
  `reviews.active` is logged and skipped, not raised: the Blade components run
  on the order confirmation page, and a 500 there is the worst possible
  failure. `reviews:doctor` is where a typo gets reported loudly.
- **No em dashes or en dashes** anywhere: code, comments, docs, commits.

## Traps that have already bitten

- `Illuminate\Support\Manager::driver($driver = null)` is **untyped**. Adding
  `?string` is a fatal incompatibility that makes the class unloadable, not a
  warning. `Reviews::driver()` is untyped on purpose, and carries a
  `@param string|null $driver` docblock instead.
  That one parameter is also why `test:types` runs at `--min=99` rather than
  bot-shield's `--min=100`. Pest 5's type-coverage plugin honours the docblock
  and reports 100%, Pest 4's does not and scores the file at 75%, which puts
  the package at 99.2%. We support both, because Pest 5 requires Laravel 13
  and PHP 8.4 while this package supports Laravel 12 and PHP 8.3. The 99 floor
  buys exactly one unavoidable untyped parameter and nothing else: if the
  number drops below 100 on a Pest 5 run, something new is genuinely untyped.
- `Order::shippingAddress()` in `marshmallow/cart` is **not** an Eloquent
  relation: it runs its own query and returns a Model, so reading
  `$order->shippingAddress` throws `LogicException`.
  `DerivesReviewableOrder::reviewRelated()` falls back to calling the method.
  `tests/Fixtures/TestOrder` reproduces that shape deliberately; do not
  simplify it to a `belongsTo` or the tests will pass while production breaks.
- `Illuminate\Support\HtmlString` implements `Htmlable`, **not** `Renderable`.
  A provider returning raw markup wraps it in `Support\Html`.
- Kiyoh's language list is not ISO-639-1. It is a fixed list of 29 values, some
  regional. See `KiyohProvider::LOCALES` and the alias table next to it.

## Adding a provider

1. `src/Providers/<Name>Provider.php` implementing `ReviewProvider` plus the
   capability interfaces it can honour.
2. A `create<Name>Driver()` method on `Reviews`, and the name in `available()`.
3. A `providers.<name>` block in `config/reviews.php`.
4. Tests, including a not-configured path that asserts no HTTP request is made,
   and a privacy test proving the provider's error body cannot leak an address.
5. The capability table in `README.md` and in `docs/BRIEF.md`.

A site can do all of this without us via `Reviews::extend()`. That is the point
of the design, so keep it working.

## Quick Commands

- Full validation: `composer test` (phpstan, pint, 100% type coverage, pest)
- Formatting: `composer lint` / `composer lint:check`
- Static analysis: `composer analyse` (PHPStan level 8)
- Pest tests: `composer test:unit`
- Rector: `composer refactor:check` / `composer refactor`. Deliberately **not**
  in `composer test` and **not** in CI: it is a tool we run on purpose, not a
  gate that blocks a pull request over a disagreement with Pint.
- Workbench: `composer build` / `composer serve`

## Local Skills

- `package-scaffold`: adding package capabilities and wiring them through the
  service provider.
- `package-testing`: Pest 5 and Orchestra Testbench.
- `package-release`: changelog, release notes, tags, release workflow.
- `package-compatibility`: PHP and Laravel support matrix.
- `package-generate-skill`: updating the bundled Boost skill.
