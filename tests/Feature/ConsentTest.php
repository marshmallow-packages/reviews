<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Marshmallow\Reviews\Providers\GoogleProvider;
use Marshmallow\Reviews\Support\Gate;

function withConsent(bool $granted): void
{
    config()->set('reviews.consent', static fn (): bool => $granted);
}

it('grants consent when no callback is configured', function (): void {
    config()->set('reviews.consent', null);

    expect(app(Gate::class)->mayRender())->toBeTrue()
        ->and(reviewManager()->hasConsent())->toBeTrue();
});

it('withholds consent when the callback says no', function (): void {
    withConsent(false);

    expect(app(Gate::class)->mayRender())->toBeFalse()
        ->and(reviewManager()->hasConsent())->toBeFalse();
});

it('withholds consent while the master switch is off, whatever the callback says', function (): void {
    withConsent(true);

    config()->set('reviews.enabled', false);

    expect(reviewManager()->hasConsent())->toBeFalse();
});

it('renders no opt-in and no badge through the fan-out without consent', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    withConsent(false);

    $invitation = makeInvitation(estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'));

    expect(reviewManager()->optInAll($invitation)->toHtml())->toBe('')
        ->and(reviewManager()->badgeAll()->toHtml())->toBe('');

    withConsent(true);

    expect(reviewManager()->optInAll($invitation)->toHtml())->not->toBe('')
        ->and(reviewManager()->badgeAll()->toHtml())->not->toBe('');
});

/*
 * Closing the bypass. Gating only the fan-out would leave
 * Reviews::driver('google')->badge() free to load Google's script and set its
 * cookies, which is exactly the call a site makes for one badge in one place.
 */
it('blocks a provider resolved directly, not only the fan-out', function (): void {
    configureGoogle();

    withConsent(false);

    $google = reviewManager()->driver('google');

    expect($google)->toBeInstanceOf(GoogleProvider::class);

    /** @var GoogleProvider $google */
    expect($google->optIn(makeInvitation(estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'))))->toBeNull()
        ->and($google->badge())->toBeNull()
        // Still configured: it is declining, not misconfigured.
        ->and($google->isConfigured())->toBeTrue();
});

/*
 * The distinction the whole design rests on. Posting an invitation to Kiyoh is
 * a controller to processor transfer under the data processing agreement, not a
 * cookie placement, so a cookie banner has no bearing on it.
 */
it('does not let a withheld consent stop a server side invitation', function (): void {
    configureKiyoh(['reviews.active' => ['kiyoh']]);

    withConsent(false);

    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $results = reviewManager()->inviteAll(makeInvitation());

    expect($results)->toHaveCount(1)
        ->and($results[0]->wasSent())->toBeTrue();

    Http::assertSentCount(1);
});
