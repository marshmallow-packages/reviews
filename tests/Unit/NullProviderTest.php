<?php

declare(strict_types=1);

use Marshmallow\Reviews\Contracts\ImportsReviews;
use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Contracts\RespondsToReviews;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\CollectedReview;
use Marshmallow\Reviews\Data\ReviewQuery;
use Marshmallow\Reviews\Data\ReviewResponse;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Providers\NullProvider;

/*
 * It implements every capability interface on purpose: that makes "the whole
 * contract is satisfiable" a fact the type system checks rather than a claim.
 */
it('implements every capability interface', function (string $contract): void {
    expect(new NullProvider)->toBeInstanceOf($contract);
})->with([
    SendsInvitations::class,
    RendersOptIn::class,
    RendersBadge::class,
    ImportsReviews::class,
    RespondsToReviews::class,
    ProvidesReviewLink::class,
]);

it('names itself and admits it is not configured', function (): void {
    expect((new NullProvider)->name())->toBe('null')
        ->and((new NullProvider)->isConfigured())->toBeFalse();
});

/*
 * A skip rather than a success, so monitoring can tell "no provider configured
 * here" apart from "the invitation went out".
 */
it('skips an invitation as disabled instead of pretending it was sent', function (): void {
    $result = (new NullProvider)->invite(makeInvitation());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->wasSent())->toBeFalse()
        ->and($result->skipReason)->toBe(SkipReason::Disabled)
        ->and($result->provider)->toBe('null');
});

it('renders nothing and links to nothing', function (): void {
    $provider = new NullProvider;

    expect($provider->optIn(makeInvitation()))->toBeNull()
        ->and($provider->badge())->toBeNull()
        ->and($provider->summary())->toBeNull()
        ->and($provider->reviewLink())->toBeNull()
        ->and($provider->reviewLink(makeInvitation()))->toBeNull();
});

it('imports no reviews', function (): void {
    expect((new NullProvider)->reviews(ReviewQuery::all()))->toBe([]);
});

it('publishes no response', function (): void {
    $review = new CollectedReview(provider: 'null', externalId: '1', rating: 9.0);

    $result = (new NullProvider)->respond($review, new ReviewResponse('Thank you.'));

    expect($result->published)->toBeFalse()
        ->and($result->provider)->toBe('null')
        ->and($result->message)->toBe('The null provider publishes nothing.')
        ->and($result->context())->toBe([
            'provider' => 'null',
            'published' => false,
            'message' => 'The null provider publishes nothing.',
            'status' => null,
        ]);
});
