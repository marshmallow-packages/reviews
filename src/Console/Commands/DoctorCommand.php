<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Marshmallow\Reviews\Contracts\ImportsReviews;
use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Contracts\RespondsToReviews;
use Marshmallow\Reviews\Contracts\ReviewProvider;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Reviews;
use Marshmallow\Reviews\Support\ConfigValue;
use Throwable;

/**
 * A read-only diagnostic. It resolves providers and reads config, and changes
 * nothing: running it on production is safe by construction.
 *
 * The failures it looks for are the silent ones. A provider that renders in the
 * browser without a consent gate, and Google refusing to render because no
 * delivery date reaches it, both leave a site that looks fine and collects no
 * reviews.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'reviews:doctor';

    protected $description = 'Check that review collection is wired up and configured correctly.';

    private int $failures = 0;

    private int $warnings = 0;

    public function __construct(
        private readonly Reviews $reviews,
        private readonly Repository $config,
        private readonly Translator $translator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /*
         * Artisan keeps one command instance, so a second run in the same
         * process would otherwise inherit the first run's tally.
         */
        $this->failures = 0;
        $this->warnings = 0;

        $this->components->info($this->message('title'));

        $this->checkMasterSwitch();

        $providers = $this->resolveActiveProviders();

        $this->reportCapabilities($providers);
        $this->checkConsent($providers);
        $this->checkEstimatedDeliveryDate($providers);
        $this->noteClientSideRequirements($providers);
        $this->checkEvents();
        $this->reportQueue();

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error($this->message('problems', ['count' => (string) $this->failures]));

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->components->warn($this->message('warnings', ['count' => (string) $this->warnings]));

            return self::SUCCESS;
        }

        $this->components->info($this->message('healthy'));

        return self::SUCCESS;
    }

    private function checkMasterSwitch(): void
    {
        if ($this->reviews->enabled()) {
            $this->reportPass($this->label('package'), $this->message('enabled'));

            return;
        }

        $this->reportWarning($this->label('package'), $this->message('disabled'));
    }

    /**
     * Resolves by configured name rather than through Reviews::active(), so a
     * name that cannot be resolved is reported as itself instead of taking the
     * whole command down on the first bad driver.
     *
     * @return array<string, ReviewProvider>
     */
    private function resolveActiveProviders(): array
    {
        $this->reportDetail($this->label('default_provider'), $this->reviews->getDefaultDriver());

        /*
         * The manager's own list rather than a second reading of config. A
         * doctor that parses the configuration differently from the thing it
         * is diagnosing can report a setup that is not the one running.
         */
        $names = $this->reviews->activeNames();

        if ($names === []) {
            $this->reportWarning($this->label('active_providers'), $this->message('none_active'));

            return [];
        }

        $this->reportDetail($this->label('active_providers'), implode(', ', $names));

        $providers = [];

        foreach ($names as $name) {
            try {
                $providers[$name] = $this->reviews->driver($name);
            } catch (Throwable $exception) {
                $this->reportFailure(
                    $this->label('active_providers'),
                    $this->message('provider_unresolved', [
                        'provider' => $name,
                        'message' => $exception->getMessage(),
                    ]),
                );
            }
        }

        return $providers;
    }

    /**
     * @param  array<string, ReviewProvider>  $providers
     */
    private function reportCapabilities(array $providers): void
    {
        if ($providers === []) {
            return;
        }

        $rows = [];

        foreach ($providers as $provider) {
            $rows[] = [
                $provider->name(),
                $provider->isConfigured() ? $this->message('yes') : $this->message('no'),
                implode(', ', $this->capabilities($provider)),
            ];
        }

        $this->newLine();

        $this->table(
            [
                $this->message('columns.provider'),
                $this->message('columns.configured'),
                $this->message('columns.capabilities'),
            ],
            $rows,
        );
    }

    /**
     * @return list<string>
     */
    private function capabilities(ReviewProvider $provider): array
    {
        $capabilities = [
            SendsInvitations::class => 'send',
            RendersOptIn::class => 'opt_in',
            RendersBadge::class => 'badge',
            ImportsReviews::class => 'import',
            RespondsToReviews::class => 'respond',
            ProvidesReviewLink::class => 'link',
        ];

        $supported = [];

        foreach ($capabilities as $interface => $key) {
            if ($provider instanceof $interface) {
                $supported[] = $this->message('capabilities.'.$key);
            }
        }

        return $supported;
    }

    /**
     * Client side rendering is the only thing consent gates, so the warning is
     * raised only when a provider that renders in the browser is active. Such a
     * provider sets cookies and transfers a customer email address from the
     * visitor's own browser, which a cookie banner is exactly for.
     *
     * @param  array<string, ReviewProvider>  $providers
     */
    private function checkConsent(array $providers): void
    {
        if (is_callable($this->config->get('reviews.consent'))) {
            $this->reportPass($this->label('consent'), $this->message('consent_configured'));

            return;
        }

        $renderers = array_filter(
            $providers,
            static fn (ReviewProvider $provider): bool => $provider instanceof RendersOptIn,
        );

        if ($renderers === []) {
            $this->reportDetail($this->label('consent'), $this->message('consent_missing'));

            return;
        }

        $this->reportWarning($this->label('consent'), $this->message('consent_missing_with_opt_in', [
            'providers' => implode(', ', array_keys($renderers)),
        ]));
    }

    /**
     * Google decides when its survey is sent from the delivery date and refuses
     * to render its module without one, so a missing resolver is not a warning:
     * it is a site that collects nothing while looking correctly configured.
     *
     * @param  array<string, ReviewProvider>  $providers
     */
    private function checkEstimatedDeliveryDate(array $providers): void
    {
        if (is_callable($this->config->get('reviews.estimated_delivery_date'))) {
            $this->reportPass($this->label('delivery_date'), $this->message('delivery_date_configured'));

            return;
        }

        if (! array_key_exists(Reviews::GOOGLE, $providers)) {
            $this->reportDetail($this->label('delivery_date'), $this->message('delivery_date_missing'));

            return;
        }

        $this->reportFailure($this->label('delivery_date'), $this->message('delivery_date_required'));
    }

    /**
     * What this command cannot check, said out loud.
     *
     * Google declines to render unless the invitation carries an email
     * address, a delivery country and an estimated delivery date. Only the
     * last of those is visible in config: the other two live on each order, so
     * a shop selling digital goods, where there is no shipping address and
     * therefore no country, is configured perfectly and collects nothing. That
     * is the most likely silent failure this package has, and a diagnostic
     * that stays quiet about the thing it cannot see is worse than useless.
     *
     * @param  array<string, ReviewProvider>  $providers
     */
    private function noteClientSideRequirements(array $providers): void
    {
        if (! array_key_exists(Reviews::GOOGLE, $providers)) {
            return;
        }

        $this->reportDetail($this->label('google_requirements'), $this->message('google_requirements'));
    }

    private function checkEvents(): void
    {
        if (! ConfigValue::bool($this->config->get('reviews.events.enabled', false))) {
            $this->reportDetail($this->label('events'), $this->message('events_disabled'));

            return;
        }

        $events = $this->listenedEvents();

        if ($events === []) {
            $this->reportWarning($this->label('events'), $this->message('events_without_listeners'));

            return;
        }

        $this->reportPass($this->label('events'), $this->message('events_enabled', [
            'count' => (string) count($events),
            'events' => implode(', ', $events),
        ]));
    }

    /**
     * @return list<string>
     */
    private function listenedEvents(): array
    {
        $configured = $this->config->get('reviews.events.listen');

        if (! is_array($configured)) {
            return [];
        }

        $events = [];

        foreach ($configured as $event) {
            if (is_string($event) && $event !== '') {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function reportQueue(): void
    {
        $this->reportDetail($this->label('queue'), $this->message('queue', [
            'connection' => $this->stringOrDefault($this->config->get('reviews.queue.connection')),
            'queue' => $this->stringOrDefault($this->config->get('reviews.queue.queue')),
        ]));
    }

    private function stringOrDefault(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : $this->message('default_queue');
    }

    private function label(string $key): string
    {
        return $this->message('labels.'.$key);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function message(string $key, array $replace = []): string
    {
        $line = $this->translator->get('reviews::messages.doctor.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }

    private function reportPass(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=green>{$detail}</>");
    }

    private function reportDetail(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=gray>{$detail}</>");
    }

    private function reportWarning(string $label, string $detail): void
    {
        $this->warnings++;

        $this->components->twoColumnDetail($label, "<fg=yellow>{$detail}</>");
    }

    private function reportFailure(string $label, string $detail): void
    {
        $this->failures++;

        $this->components->twoColumnDetail($label, "<fg=red>{$detail}</>");
    }
}
