<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Http;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Providers\NullProvider;
use Marshmallow\Reviews\Support\Html;
use Marshmallow\Reviews\Tests\Fixtures\RecordingProvider;

/**
 * A provider that only renders, to prove that concatenation walks more than one
 * provider and keeps their order.
 */
function optInRenderer(string $name, string $markup): RendersOptIn&RendersBadge
{
    return new class($name, $markup) implements RendersBadge, RendersOptIn
    {
        public function __construct(
            private readonly string $providerName,
            private readonly string $markup,
        ) {}

        public function name(): string
        {
            return $this->providerName;
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function optIn(ReviewInvitation $invitation): ?Renderable
        {
            return new Html($this->markup);
        }

        public function badge(): ?Renderable
        {
            return new Html($this->markup);
        }
    };
}

it('falls back to the default provider alone when nothing is active', function (): void {
    config()->set([
        'reviews.default' => 'null',
        'reviews.active' => null,
    ]);

    expect(reviewManager()->active())->toHaveCount(1)
        ->and(reviewManager()->active()[0])->toBeInstanceOf(NullProvider::class);
});

it('treats an empty active list as the default provider alone', function (): void {
    config()->set([
        'reviews.default' => 'google',
        'reviews.active' => [],
    ]);

    expect(reviewManager()->active())->toHaveCount(1)
        ->and(reviewManager()->active()[0]->name())->toBe('google');
});

it('activates nothing at all while the master switch is off', function (): void {
    config()->set([
        'reviews.enabled' => false,
        'reviews.active' => ['kiyoh', 'google'],
    ]);

    expect(reviewManager()->active())->toBe([])
        ->and(reviewManager()->supporting(SendsInvitations::class))->toBe([])
        ->and(reviewManager()->inviteAll(makeInvitation()))->toBe([]);
});

it('filters the active providers by capability', function (): void {
    config()->set('reviews.active', ['kiyoh', 'google']);

    $senders = reviewManager()->supporting(SendsInvitations::class);
    $renderers = reviewManager()->supporting(RendersOptIn::class);

    expect(array_map(static fn (SendsInvitations $p): string => $p->name(), $senders))->toBe(['kiyoh'])
        ->and(array_map(static fn (RendersOptIn $p): string => $p->name(), $renderers))->toBe(['google']);
});

it('returns one result per active provider', function (): void {
    configureKiyoh(['reviews.active' => ['kiyoh', 'null']]);

    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $results = reviewManager()->inviteAll(makeInvitation());

    expect($results)->toHaveCount(2)
        ->and($results[0]->provider)->toBe('kiyoh')
        ->and($results[0]->wasSent())->toBeTrue()
        ->and($results[1]->provider)->toBe('null')
        ->and($results[1]->wasSkipped())->toBeTrue();
});

/*
 * Deliberate: a provider that renders client side is reported rather than left
 * out, so monitoring can tell "nothing sends here by design" apart from
 * "nothing sent".
 */
it('reports a provider that cannot send as a client side only skip', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    $results = reviewManager()->inviteAll(makeInvitation());

    expect($results)->toHaveCount(1)
        ->and($results[0]->provider)->toBe('google')
        ->and($results[0]->wasSkipped())->toBeTrue()
        ->and($results[0]->skipReason)->toBe(SkipReason::ClientSideOnly)
        ->and($results[0]->message)->toBe(SkipReason::ClientSideOnly->label());
});

it('concatenates the opt-in markup of every active provider that renders one', function (): void {
    reviewManager()->extend('first', fn (): RendersOptIn => optInRenderer('first', '<i>one</i>'));
    reviewManager()->extend('second', fn (): RendersOptIn => optInRenderer('second', '<i>two</i>'));

    config()->set('reviews.active', ['first', 'null', 'second']);

    expect(reviewManager()->optInAll(makeInvitation())->toHtml())->toBe('<i>one</i><i>two</i>');
});

it('concatenates the badge markup of every active provider that renders one', function (): void {
    reviewManager()->extend('first', fn (): RendersBadge => optInRenderer('first', '<i>one</i>'));
    reviewManager()->extend('recording', fn (): RecordingProvider => new RecordingProvider);

    config()->set('reviews.active', ['first', 'recording']);

    expect(reviewManager()->badgeAll()->toHtml())
        ->toBe('<i>one</i><span class="recording-badge"></span>');
});

it('collects the review link of every active provider that publishes one', function (): void {
    configureKiyoh(['reviews.active' => ['kiyoh', 'null']]);

    expect(reviewManager()->reviewLinks())
        ->toBe(['kiyoh' => 'https://www.klantenvertellen.nl/profile/1234567']);
});
