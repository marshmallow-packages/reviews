<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Marshmallow\Reviews\Data\CollectedReview;
use Marshmallow\Reviews\Data\ResponseResult;
use Marshmallow\Reviews\Data\ReviewResponse;

/**
 * IMPORT. The provider accepts a published reply to a review it collected.
 *
 * Separate from ImportsReviews because reading and replying are separately
 * licensed on some platforms: a provider can expose a public feed while
 * gating moderation behind a paid tier.
 */
interface RespondsToReviews extends ReviewProvider
{
    public function respond(CollectedReview $review, ReviewResponse $response): ResponseResult;
}
