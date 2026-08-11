<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Marshmallow\Reviews\Tests\Fixtures\PlainOrder;
use Marshmallow\Reviews\Tests\Fixtures\RecordingProvider;

it('renders the badge markup of the active providers', function (): void {
    reviewManager()->extend('recording', fn (): RecordingProvider => new RecordingProvider);

    config()->set('reviews.active', ['recording']);

    expect(Blade::render('<x-reviews::badge />'))->toBe('<span class="recording-badge"></span>');
});

it('renders absolutely nothing when no provider publishes a badge', function (): void {
    config()->set('reviews.active', ['null']);

    expect(Blade::render('<x-reviews::badge />'))->toBe('');
});

it('renders absolutely nothing for the badge when consent is withheld', function (): void {
    reviewManager()->extend('recording', fn (): RecordingProvider => new RecordingProvider);

    config()->set([
        'reviews.active' => ['recording'],
        'reviews.consent' => static fn (): bool => false,
    ]);

    expect(Blade::render('<x-reviews::badge />'))->toBe('');
});

it('accepts a reviewable order on the opt-in component', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    $order = new PlainOrder(
        reference: 'PLAIN-7',
        deliveryDate: CarbonImmutable::parse('2026-08-20'),
    );

    $html = Blade::render('<x-reviews::opt-in :order="$order" />', ['order' => $order]);

    expect($html)->toContain('window.gapi.surveyoptin.render')
        ->and($html)->toContain('PLAIN-7');
});

it('accepts a ready made invitation on the opt-in component', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    $invitation = makeInvitation(
        orderReference: 'ORDER-77',
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
    );

    $html = Blade::render('<x-reviews::opt-in :order="$invitation" />', ['invitation' => $invitation]);

    expect($html)->toContain('ORDER-77');
});

it('renders absolutely nothing when the opt-in has nothing to show', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    // No estimated delivery date, so Google declines for this one order.
    $order = new PlainOrder(reference: 'PLAIN-8');

    expect(Blade::render('<x-reviews::opt-in :order="$order" />', ['order' => $order]))->toBe('');
});

it('does not put the email address into the view data of the component', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    $order = new PlainOrder(
        email: 'privacy@example.test',
        reference: 'PLAIN-9',
        deliveryDate: CarbonImmutable::parse('2026-08-20'),
    );

    $html = Blade::render('<x-reviews::opt-in :order="$order" />', ['order' => $order]);

    // Google needs the address, so it is in the snippet exactly once, inside
    // the encoded email field, and nowhere else in the component's own markup.
    expect(substr_count($html, 'privacy@example.test'))->toBe(1);
});

it('does not escape the provider markup a second time', function (): void {
    reviewManager()->extend('recording', fn (): RecordingProvider => new RecordingProvider);

    config()->set('reviews.active', ['recording']);

    expect(Blade::render('<x-reviews::badge />'))
        ->not->toContain('&lt;span')
        ->not->toContain('&quot;');
});
