<?php

declare(strict_types=1);

namespace Marshmallow\Reviews;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Contracts\ReviewProvider;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Exceptions\UnknownReviewProvider;
use Marshmallow\Reviews\Providers\GoogleProvider;
use Marshmallow\Reviews\Providers\KiyohProvider;
use Marshmallow\Reviews\Providers\NullProvider;
use Marshmallow\Reviews\Support\ExceptionReporter;
use Marshmallow\Reviews\Support\Gate;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolution is Socialite's: extend Laravel's own Manager and get driver(),
 * extend(), driver memoisation and the default lookup for free. Reviews::extend()
 * both adds a provider and overrides a bundled one, because Manager consults
 * $customCreators before the create*Driver() convention.
 *
 * On top of that sits a fan-out layer Socialite has no need for. You log a user
 * in through one provider, but a webshop legitimately runs Google Customer
 * Reviews for its Google seller rating alongside Kiyoh for the on-site badge
 * and the invitation email. The *All() methods walk config('reviews.active'),
 * which defaults to the single default provider.
 *
 * @mixin ReviewProvider
 */
class Reviews extends Manager
{
    public const string KIYOH = 'kiyoh';

    public const string GOOGLE = 'google';

    public const string NONE = 'null';

    public function getDefaultDriver(): string
    {
        $default = $this->config->get('reviews.default', self::NONE);

        return is_string($default) && $default !== '' ? $default : self::NONE;
    }

    /**
     * Rethrows Manager's bare "Driver [x] not supported." as something that
     * names what is available.
     *
     * The parameter is deliberately untyped. Illuminate\Support\Manager
     * declares driver($driver = null) with no type at all, and narrowing it to
     * ?string is a fatal incompatibility, not a warning. The return type is
     * fine: covariance against no declared return type is allowed.
     *
     * @param  string|null  $driver
     */
    #[Override]
    public function driver($driver = null): ReviewProvider
    {
        $name = is_string($driver) && $driver !== '' ? $driver : $this->getDefaultDriver();

        try {
            $resolved = parent::driver($driver);
        } catch (InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'not supported')) {
                throw UnknownReviewProvider::named($name, $this->available());
            }

            throw $e;
        }

        if (! $resolved instanceof ReviewProvider) {
            throw UnknownReviewProvider::isNotAProvider($name);
        }

        return $resolved;
    }

    /**
     * Every provider name that can be resolved right now: the bundled three
     * plus anything registered through extend().
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_unique([
            self::KIYOH,
            self::GOOGLE,
            self::NONE,
            ...array_keys($this->customCreators),
        ]));
    }

    /**
     * The providers taking part in the fan-out. Falls back to the default
     * provider alone, so a site that never sets reviews.active behaves exactly
     * like Socialite.
     *
     * @return list<ReviewProvider>
     */
    public function active(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $providers = [];

        foreach ($this->activeNames() as $name) {
            try {
                $providers[] = $this->driver($name);
            } catch (UnknownReviewProvider $e) {
                /*
                 * A typo in reviews.active must not take down the order
                 * confirmation page. The Blade components exist precisely to
                 * render nothing rather than break a page, and letting this
                 * escape would turn a config mistake into a 500 at the worst
                 * possible moment in a checkout.
                 *
                 * Not silent, though: it is logged, and reviews:doctor reports
                 * an unresolvable name as an error rather than a warning.
                 */
                $this->logUnknownProvider($e);
            }
        }

        return $providers;
    }

    /**
     * The configured provider names, before resolution. Public so
     * reviews:doctor reports on exactly the list the manager will use: a
     * second parser in the command could drift from this one, and drift there
     * means the doctor blessing a configuration that is not what runs.
     *
     * @return list<string>
     */
    public function activeNames(): array
    {
        $names = $this->config->get('reviews.active');

        if (! is_array($names) || $names === []) {
            return [$this->getDefaultDriver()];
        }

        return array_values(array_filter(
            $names,
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));
    }

    private function reportException(Throwable $e): void
    {
        $this->container->make(ExceptionReporter::class)->report($e);
    }

    private function logUnknownProvider(UnknownReviewProvider $e): void
    {
        try {
            $this->container->make(LoggerInterface::class)->warning($e->getMessage());
        } catch (Throwable) {
            // A container without a logger is not worth failing a page render
            // over. The doctor command still reports it.
        }
    }

    /**
     * The active providers implementing one capability interface.
     *
     * @template T of ReviewProvider
     *
     * @param  class-string<T>  $capability
     * @return list<T>
     */
    public function supporting(string $capability): array
    {
        return array_values(array_filter(
            $this->active(),
            static fn (ReviewProvider $provider): bool => $provider instanceof $capability,
        ));
    }

    /**
     * Hands the invitation to every active provider that can send one.
     *
     * Providers that only render client side are still reported, as a skip
     * with ClientSideOnly, so monitoring can tell "nothing sends here by
     * design" apart from "nothing sent".
     *
     * @return list<InvitationResult>
     */
    public function inviteAll(ReviewInvitation $invitation): array
    {
        $results = [];

        foreach ($this->active() as $provider) {
            if (! $provider instanceof SendsInvitations) {
                $results[] = InvitationResult::skipped($provider->name(), SkipReason::ClientSideOnly);

                continue;
            }

            try {
                $results[] = $provider->invite($invitation);
            } catch (Throwable $e) {
                /*
                 * The bundled providers report rather than throw, but a
                 * provider registered through extend() is somebody else's
                 * code. Without this, "a provider reports, it does not throw"
                 * would only be true of the three we wrote, and a controller
                 * calling inviteAll() directly would inherit their bugs.
                 *
                 * The exception message is deliberately dropped from the
                 * result: it is not ours and may quote a request body
                 * containing the customer's email address. The full exception
                 * goes to Sentry instead, so swallowing it here does not make
                 * a third party provider's bug invisible.
                 */
                $this->reportException($e);

                $results[] = InvitationResult::failed($provider->name(), 'Provider threw '.$e::class.'.');
            }
        }

        return $results;
    }

    /**
     * Concatenated opt-in output of every active provider that renders one.
     * Empty when consent is withheld or nothing renders.
     */
    public function optInAll(ReviewInvitation $invitation): HtmlString
    {
        if (! $this->hasConsent()) {
            return new HtmlString('');
        }

        return $this->concatenate(array_map(
            static fn (RendersOptIn $provider): ?Renderable => $provider->optIn($invitation),
            $this->supporting(RendersOptIn::class),
        ));
    }

    /**
     * Concatenated badge output of every active provider that renders one.
     */
    public function badgeAll(): HtmlString
    {
        if (! $this->hasConsent()) {
            return new HtmlString('');
        }

        return $this->concatenate(array_map(
            static fn (RendersBadge $provider): ?Renderable => $provider->badge(),
            $this->supporting(RendersBadge::class),
        ));
    }

    /**
     * Review links from every active provider that publishes one, keyed by
     * provider name. For dropping into your own transactional email.
     *
     * @return array<string, string>
     */
    public function reviewLinks(?ReviewInvitation $invitation = null): array
    {
        $links = [];

        foreach ($this->supporting(ProvidesReviewLink::class) as $provider) {
            $link = $provider->reviewLink($invitation);

            if ($link !== null && $link !== '') {
                $links[$provider->name()] = $link;
            }
        }

        return $links;
    }

    /**
     * The master switch. Everything else checks this first.
     */
    public function enabled(): bool
    {
        return $this->gate()->enabled();
    }

    /**
     * Gates client side rendering only. Sending an invitation server side is a
     * processor transfer under the DPA, not a cookie placement, so it is
     * deliberately not gated here.
     *
     * Providers that render into the browser check this for themselves as
     * well, so resolving one directly is not a way around it.
     */
    public function hasConsent(): bool
    {
        return $this->gate()->mayRender();
    }

    private function gate(): Gate
    {
        return $this->container->make(Gate::class);
    }

    protected function createKiyohDriver(): KiyohProvider
    {
        return $this->container->make(KiyohProvider::class);
    }

    protected function createGoogleDriver(): GoogleProvider
    {
        return $this->container->make(GoogleProvider::class);
    }

    protected function createNullDriver(): NullProvider
    {
        return $this->container->make(NullProvider::class);
    }

    /**
     * @param  list<Renderable|null>  $renderables
     */
    private function concatenate(array $renderables): HtmlString
    {
        $html = '';

        foreach ($renderables as $renderable) {
            if ($renderable instanceof Renderable) {
                $html .= $renderable->render();
            }
        }

        return new HtmlString($html);
    }
}
