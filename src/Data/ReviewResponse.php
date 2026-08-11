<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

/**
 * A reply to publish on a review.
 */
final readonly class ReviewResponse
{
    public function __construct(
        public string $body,
        /** Public replies show under the review, private ones only reach the reviewer. */
        public bool $public = true,
        /** Whether the provider should email the reviewer about the reply. */
        public bool $notifyReviewer = false,
    ) {}
}
