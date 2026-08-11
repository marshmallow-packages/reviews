# Contribution Guide

Thank you for considering contributing to Reviews! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).
- Do not use em dashes or en dashes anywhere: code, comments, documentation or commit messages. Use commas, colons, periods or parentheses.

## Package specific rules

These are not style preferences, they are the guarantees the package makes. A
pull request that breaks one of them will be asked to change.

- **Never log an email address, a customer name or a city.** Every log line goes through a `context()` method built to exclude them, and `InvitationResult` redacts addresses in its own factory. New logging goes through the same path.
- **Capability is a type.** If a provider cannot do something, it does not implement that interface. Do not add a method that returns null to mean "unsupported".
- **Consent gates rendering, never sending.** A server side invitation is a processor transfer under the data processing agreement, not a cookie placement.
- **`Support\Gate` is checked inside providers, not only in the fan-out.** Gating one level leaves a directly resolved provider free to ignore the master switch.
- **Nothing customer facing throws over configuration.** The Blade components run on the order confirmation page.
- **A swallowed exception goes to Sentry.** The queued job never rethrows, so `Support\ExceptionReporter` is the only thing between a provider bug and silence.

`AGENTS.md` documents the reasoning behind each of these, plus the traps that
have already caught us.

## Static analysis

```bash
composer analyse
```

PHPStan runs at level 8. Do not add a baseline entry or an inline ignore to
silence a finding: fix the cause, or if the analyser is genuinely wrong, add a
scoped ignore in `phpstan.neon.dist` with the reasoning written next to it.

## Refactoring

```bash
composer refactor:check
composer refactor
```

Rector is deliberately not part of `composer test` and not a CI step. It is a
tool we run on purpose and review the diff of, not a gate that blocks a pull
request over a disagreement with Pint.

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```
