<?php

declare(strict_types=1);

use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Enums\InvitationOutcome;
use Marshmallow\Reviews\Enums\SkipReason;

it('builds a sent result', function (): void {
    $result = InvitationResult::sent('kiyoh', 'INV-1');

    expect($result->outcome)->toBe(InvitationOutcome::Sent)
        ->and($result->wasSent())->toBeTrue()
        ->and($result->wasSkipped())->toBeFalse()
        ->and($result->hasFailed())->toBeFalse()
        ->and($result->reference)->toBe('INV-1')
        ->and($result->skipReason)->toBeNull()
        ->and($result->message)->toBeNull()
        ->and($result->status)->toBeNull();
});

it('builds a skipped result carrying the reason and its label', function (SkipReason $reason): void {
    $result = InvitationResult::skipped('kiyoh', $reason);

    expect($result->outcome)->toBe(InvitationOutcome::Skipped)
        ->and($result->wasSkipped())->toBeTrue()
        ->and($result->hasFailed())->toBeFalse()
        ->and($result->skipReason)->toBe($reason)
        ->and($result->message)->toBe($reason->label());
})->with(SkipReason::cases());

it('builds a failed result carrying the status', function (): void {
    $result = InvitationResult::failed('kiyoh', 'The provider returned an error.', 503);

    expect($result->outcome)->toBe(InvitationOutcome::Failed)
        ->and($result->hasFailed())->toBeTrue()
        ->and($result->wasSent())->toBeFalse()
        ->and($result->message)->toBe('The provider returned an error.')
        ->and($result->status)->toBe(503);
});

it('offers a context of scalars only, safe to write to a log', function (): void {
    expect(InvitationResult::skipped('google', SkipReason::ClientSideOnly)->context())->toBe([
        'provider' => 'google',
        'outcome' => 'skipped',
        'skip_reason' => 'client_side_only',
        'reference' => null,
        'message' => 'Provider renders client side instead of sending',
        'status' => null,
    ]);

    expect(InvitationResult::sent('kiyoh', 'INV-1')->context())->toBe([
        'provider' => 'kiyoh',
        'outcome' => 'sent',
        'skip_reason' => null,
        'reference' => 'INV-1',
        'message' => null,
        'status' => null,
    ]);
});

it('gives every skip reason a readable label', function (SkipReason $reason): void {
    expect($reason->label())->not->toBe('')
        ->and($reason->label())->not->toBe($reason->value);
})->with(SkipReason::cases());
