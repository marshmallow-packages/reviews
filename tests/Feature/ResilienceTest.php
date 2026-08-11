<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Marshmallow\Reviews\Contracts\ReviewProvider;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;

/**
 * What happens when the configuration is wrong or a provider misbehaves.
 *
 * Both cases below reach a customer facing page. The Blade components exist to
 * render nothing rather than break a layout, and an order confirmation page is
 * the single worst place in a webshop to throw a 500: the customer has already
 * paid, and the page they land on is the one that fails.
 */
it('renders nothing rather than throwing when reviews.active has a typo', function (): void {
    config()->set('reviews.active', ['kioyh']);

    expect(Blade::render('<x-reviews::badge />'))->toBe('');
});

it('skips an unresolvable provider name instead of failing the fan-out', function (): void {
    configureKiyoh();

    config()->set('reviews.active', ['kioyh', 'null']);

    $active = reviewManager()->active();

    expect($active)->toHaveCount(1)
        ->and($active[0])->toBeInstanceOf(ReviewProvider::class)
        ->and($active[0]->name())->toBe('null');
});

it('turns a throwing custom provider into a failed result rather than an exception', function (): void {
    reviewManager()->extend('exploding', fn (): SendsInvitations => explodingProvider());

    config()->set('reviews.active', ['exploding']);

    $results = reviewManager()->inviteAll(makeInvitation());

    expect($results)->toHaveCount(1)
        ->and($results[0]->hasFailed())->toBeTrue()
        ->and($results[0]->message)->toContain('RuntimeException');
});

it('redacts an email address out of any provider failure message', function (): void {
    // A provider written by somebody else has no obligation to scrub its own
    // messages, and the job writes this straight to a log file.
    $result = InvitationResult::failed('somebody-elses', 'Rejected klant@example.test for order 1');

    expect($result->message)->not->toContain('klant@example.test')
        ->and($result->message)->toContain('[email redacted]')
        ->and($result->context()['message'])->not->toContain('klant@example.test');
});
