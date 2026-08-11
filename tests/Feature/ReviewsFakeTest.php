<?php

declare(strict_types=1);

use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Facades\Reviews;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Reviews as ReviewManager;
use Marshmallow\Reviews\Testing\ReviewsFake;
use PHPUnit\Framework\ExpectationFailedException;

it('records every invitation handed to the fan-out', function (): void {
    $fake = Reviews::fake();

    $fake->inviteAll(makeInvitation(orderReference: 'FAKE-1'));
    $fake->inviteAll(makeInvitation(orderReference: 'FAKE-2'));

    expect($fake->invitations())->toHaveCount(2)
        ->and($fake->invitations()[0]->orderReference)->toBe('FAKE-1');
});

it('records a single provider send as well', function (): void {
    $fake = Reviews::fake();

    $result = $fake->invite(makeInvitation(orderReference: 'FAKE-3'));

    expect($result->wasSent())->toBeTrue()
        ->and($result->provider)->toBe(ReviewsFake::PROVIDER)
        ->and($fake->invitations())->toHaveCount(1);
});

it('replaces the container binding as well as the facade', function (): void {
    $fake = Reviews::fake();

    expect(app(ReviewManager::class))->toBe($fake);

    // Anything type hinting the manager, the queued job included, gets the fake.
    (new SendReviewInvitation(makeInvitation(orderReference: 'INJECTED-1')))->handle(
        app(ReviewManager::class),
        app('config'),
        app('log'),
    );

    $fake->assertInvited('INJECTED-1');
});

it('passes the assertions that should pass', function (): void {
    $fake = Reviews::fake();

    $fake->assertNothingInvited();

    $fake->inviteAll(makeInvitation(orderReference: 'PASS-1'));
    $fake->inviteAll(makeInvitation(orderReference: 'PASS-1'));
    $fake->inviteAll(makeInvitation(orderReference: 'PASS-2'));

    $fake->assertInvited()
        ->assertInvited('PASS-2')
        ->assertInvited(static fn (ReviewInvitation $invitation): bool => $invitation->orderReference === 'PASS-1')
        ->assertInvitedTimes(3)
        ->assertInvitedTimes(2, static fn (ReviewInvitation $invitation): bool => $invitation->orderReference === 'PASS-1')
        ->assertNotInvited('PASS-3');
});

it('fails the assertions that should fail', function (): void {
    $fake = Reviews::fake();

    $fake->inviteAll(makeInvitation(orderReference: 'FAIL-1'));

    expect(static fn (): ReviewsFake => $fake->assertNothingInvited())
        ->toThrow(ExpectationFailedException::class, 'Expected no review invitation to be sent')
        ->and(static fn (): ReviewsFake => $fake->assertInvited('OTHER'))
        ->toThrow(ExpectationFailedException::class, 'Expected a review invitation for order [OTHER]')
        ->and(static fn (): ReviewsFake => $fake->assertInvited(
            static fn (ReviewInvitation $invitation): bool => $invitation->orderReference === 'OTHER',
        ))
        ->toThrow(ExpectationFailedException::class, 'matching the given callback')
        ->and(static fn (): ReviewsFake => $fake->assertInvitedTimes(2))
        ->toThrow(ExpectationFailedException::class, 'Expected 2 review invitation(s), but 1 were recorded')
        ->and(static fn (): ReviewsFake => $fake->assertNotInvited('FAIL-1'))
        ->toThrow(ExpectationFailedException::class, 'Expected no review invitation for order [FAIL-1]');
});

it('keeps the customer out of a failing assertion message', function (): void {
    $fake = Reviews::fake();

    $fake->inviteAll(makeInvitation(
        email: 'klant@example.test',
        orderReference: 'PRIVATE-1',
        firstName: 'Sanne',
        city: 'Utrecht',
    ));

    expect(static fn (): ReviewsFake => $fake->assertNothingInvited())
        ->toThrow(function (ExpectationFailedException $exception): void {
            expect($exception->getMessage())->toContain('PRIVATE-1')
                ->not->toContain('klant@example.test')
                ->not->toContain('Sanne')
                ->not->toContain('Utrecht');
        });
});

it('scripts the result every send returns', function (): void {
    $fake = Reviews::fake()->respondWith(InvitationResult::skipped('fake', SkipReason::Duplicate));

    expect($fake->inviteAll(makeInvitation())[0]->skipReason)
        ->toBe(SkipReason::Duplicate);
});

it('scripts a provider outage', function (): void {
    $fake = Reviews::fake()->shouldFail('The provider is down.', 503);

    $result = $fake->invite(makeInvitation());

    expect($result->hasFailed())->toBeTrue()
        ->and($result->message)->toBe('The provider is down.')
        ->and($result->status)->toBe(503)
        ->and($result->provider)->toBe(ReviewsFake::PROVIDER);

    $fake->assertInvited();
});
