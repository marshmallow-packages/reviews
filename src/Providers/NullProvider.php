<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Providers;

use Illuminate\Contracts\View\View;
use Marshmallow\Reviews\Contracts\ImportsReviews;
use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Contracts\RespondsToReviews;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\CollectedReview;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ResponseResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Data\ReviewQuery;
use Marshmallow\Reviews\Data\ReviewResponse;
use Marshmallow\Reviews\Data\ReviewSummary;
use Marshmallow\Reviews\Enums\SkipReason;

/**
 * The provider a site gets until it chooses one on purpose. It does nothing,
 * loudly enough to be visible: every invitation comes back as a skip rather
 * than as a success, so monitoring can tell "no provider configured here" apart
 * from "the invitation went out".
 *
 * It implements every capability interface on purpose. That makes "the whole
 * contract is satisfiable" a compile time fact rather than a claim, and it
 * gives ImportsReviews and RespondsToReviews a test subject from v1 even though
 * no real provider implements them yet.
 */
final class NullProvider implements ImportsReviews, ProvidesReviewLink, RendersBadge, RendersOptIn, RespondsToReviews, SendsInvitations
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function invite(ReviewInvitation $invitation): InvitationResult
    {
        return InvitationResult::skipped($this->name(), SkipReason::Disabled);
    }

    public function optIn(ReviewInvitation $invitation): ?View
    {
        return null;
    }

    public function badge(): ?View
    {
        return null;
    }

    /**
     * @return list<CollectedReview>
     */
    public function reviews(ReviewQuery $query): array
    {
        return [];
    }

    public function summary(): ?ReviewSummary
    {
        return null;
    }

    public function respond(CollectedReview $review, ReviewResponse $response): ResponseResult
    {
        return ResponseResult::failed($this->name(), 'The null provider publishes nothing.');
    }

    public function reviewLink(?ReviewInvitation $invitation = null): ?string
    {
        return null;
    }
}
