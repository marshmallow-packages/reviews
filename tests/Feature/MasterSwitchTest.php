<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Marshmallow\Reviews\Enums\SkipReason;

/**
 * Regression tests for reviews.enabled.
 *
 * The config file promises "no invitations are sent, no opt-in module or badge
 * renders", and staging depends on that promise: the whole point of the switch
 * is that a test order cannot mail a real customer. An earlier version honoured
 * it only in the fan-out, so resolving a provider directly walked straight past
 * it and posted to the live Kiyoh API.
 */
beforeEach(function (): void {
    configureKiyoh();
    configureGoogle();
});

it('sends nothing through a directly resolved provider while the switch is off', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    config()->set('reviews.enabled', false);

    $result = reviewManager()->driver('kiyoh')->invite(makeInvitation());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::Disabled);

    Http::assertNothingSent();
});

it('sends nothing through the fan-out while the switch is off', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    config()->set('reviews.active', ['kiyoh']);
    config()->set('reviews.enabled', false);

    expect(reviewManager()->inviteAll(makeInvitation()))->toBe([]);

    Http::assertNothingSent();
});

it('renders nothing through a directly resolved provider while the switch is off', function (): void {
    config()->set('reviews.enabled', false);

    expect(reviewManager()->driver('google')->badge())->toBeNull()
        ->and(reviewManager()->driver('google')->optIn(makeInvitation(
            countryCode: 'NL',
            estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
        )))->toBeNull()
        ->and(reviewManager()->driver('kiyoh')->badge())->toBeNull();
});

it('renders nothing through the blade components while the switch is off', function (): void {
    config()->set('reviews.active', ['kiyoh', 'google']);
    config()->set('reviews.enabled', false);

    expect(Blade::render('<x-reviews::badge />'))->toBe('');
});
