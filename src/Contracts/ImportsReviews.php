<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Marshmallow\Reviews\Data\CollectedReview;
use Marshmallow\Reviews\Data\ReviewQuery;
use Marshmallow\Reviews\Data\ReviewSummary;

/**
 * IMPORT. The provider can hand back the reviews it has collected, so a site
 * can store and display them itself instead of relying on the provider's
 * widget.
 *
 * No provider bundled with v1 implements this yet. It ships in the contract
 * from the start so a custom provider registered through Reviews::extend() can
 * implement importing without waiting for us, and so the v2 additions are not
 * a breaking change.
 */
interface ImportsReviews extends ReviewProvider
{
    /**
     * Returns an iterable rather than an array so an implementation can be a
     * generator. Kiyoh caps how much its feed returns per request and
     * Trustpilot paginates, and neither should be forced to hold an entire
     * review history in memory to satisfy the signature.
     *
     * @return iterable<int, CollectedReview>
     */
    public function reviews(ReviewQuery $query): iterable;

    /**
     * The provider's own aggregate, when it publishes one. Null when the
     * provider offers no summary endpoint or is not configured.
     */
    public function summary(): ?ReviewSummary;
}
