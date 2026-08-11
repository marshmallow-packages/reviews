<?php

declare(strict_types=1);

use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Facades\Reviews;

/**
 * The fake replaces the manager wholesale, so anything it does not reimplement
 * is behaviour it silently loses. A test double that disagrees with the real
 * thing about the master switch would let a test pass against the fake and
 * fail in production, which is the one failure mode a fake must not have.
 */
it('records nothing while the master switch is off', function (): void {
    $fake = Reviews::fake();

    config()->set('reviews.enabled', false);

    expect($fake->inviteAll(makeInvitation()))->toBe([]);

    $fake->assertNothingInvited();
});

it('skips a single send while the master switch is off', function (): void {
    $fake = Reviews::fake();

    config()->set('reviews.enabled', false);

    $result = $fake->invite(makeInvitation());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::Disabled);

    $fake->assertNothingInvited();
});

it('records again once the switch is back on', function (): void {
    $fake = Reviews::fake();

    config()->set('reviews.enabled', true);

    $fake->inviteAll(makeInvitation(orderReference: 'ON-1'));

    $fake->assertInvited('ON-1');
});
