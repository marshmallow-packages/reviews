<?php

declare(strict_types=1);

use Marshmallow\Reviews\Exceptions\UnknownReviewProvider;
use Marshmallow\Reviews\Providers\GoogleProvider;
use Marshmallow\Reviews\Providers\KiyohProvider;
use Marshmallow\Reviews\Providers\NullProvider;
use Marshmallow\Reviews\Tests\Fixtures\RecordingProvider;

it('resolves the configured default provider when no name is given', function (): void {
    config()->set('reviews.default', 'kiyoh');

    expect(reviewManager()->driver())->toBeInstanceOf(KiyohProvider::class)
        ->and(reviewManager()->getDefaultDriver())->toBe('kiyoh');
});

it('falls back to the null provider when the default is not a usable string', function (): void {
    config()->set('reviews.default', '');

    expect(reviewManager()->getDefaultDriver())->toBe('null')
        ->and(reviewManager()->driver())->toBeInstanceOf(NullProvider::class);
});

it('resolves each bundled provider by name', function (string $name, string $class): void {
    expect(reviewManager()->driver($name))->toBeInstanceOf($class);
})->with([
    'kiyoh' => ['kiyoh', KiyohProvider::class],
    'google' => ['google', GoogleProvider::class],
    'null' => ['null', NullProvider::class],
]);

it('names the available providers when asked for one that does not exist', function (): void {
    expect(fn (): mixed => reviewManager()->driver('trustpilot'))
        ->toThrow(
            UnknownReviewProvider::class,
            'Unknown review provider [trustpilot]. Available: google, kiyoh, null. Register a custom one with Reviews::extend().',
        );
});

it('makes a provider registered through extend resolvable by name', function (): void {
    $custom = new RecordingProvider('custom');

    reviewManager()->extend('custom', fn (): RecordingProvider => $custom);

    expect(reviewManager()->driver('custom'))->toBe($custom)
        ->and(reviewManager()->driver('custom')->name())->toBe('custom');
});

/*
 * The headline of the manager: a bundled provider is replaced by registering a
 * different one under its own name, with no subclass of the manager anywhere.
 * Manager consults the custom creators before the create*Driver() convention,
 * which is what makes this work.
 */
it('lets extend override a bundled provider without subclassing anything', function (): void {
    $replacement = new RecordingProvider('kiyoh');

    reviewManager()->extend('kiyoh', fn (): RecordingProvider => $replacement);

    $resolved = reviewManager()->driver('kiyoh');

    expect($resolved)->toBe($replacement)
        ->and($resolved)->not->toBeInstanceOf(KiyohProvider::class)
        ->and($resolved->name())->toBe('kiyoh');
});

it('sends the fan-out through an overridden bundled provider', function (): void {
    $replacement = new RecordingProvider('kiyoh');

    reviewManager()->extend('kiyoh', fn (): RecordingProvider => $replacement);

    config()->set('reviews.active', ['kiyoh']);

    $results = reviewManager()->inviteAll(makeInvitation(orderReference: 'OVERRIDE-1'));

    expect($replacement->invitations)->toHaveCount(1)
        ->and($replacement->invitations[0]->orderReference)->toBe('OVERRIDE-1')
        ->and($results)->toHaveCount(1)
        ->and($results[0]->wasSent())->toBeTrue()
        ->and($results[0]->provider)->toBe('kiyoh');
});

it('lists the bundled providers plus everything registered through extend', function (): void {
    expect(reviewManager()->available())->toBe(['kiyoh', 'google', 'null']);

    reviewManager()->extend('custom', fn (): RecordingProvider => new RecordingProvider('custom'));

    expect(reviewManager()->available())->toBe(['kiyoh', 'google', 'null', 'custom']);
});

it('does not list an overridden provider twice', function (): void {
    reviewManager()->extend('kiyoh', fn (): RecordingProvider => new RecordingProvider('kiyoh'));

    expect(reviewManager()->available())->toBe(['kiyoh', 'google', 'null']);
});

it('memoises a resolved driver', function (): void {
    expect(reviewManager()->driver('kiyoh'))->toBe(reviewManager()->driver('kiyoh'))
        ->and(reviewManager()->driver('google'))->toBe(reviewManager()->driver('google'));
});
