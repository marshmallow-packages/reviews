<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

use Carbon\CarbonImmutable;

/**
 * One review read back from a provider.
 *
 * Ratings keep their own scale rather than being normalised on the way in,
 * because Kiyoh scores out of 10 and Google and Trustpilot out of 5, and
 * throwing that away would make a stored review impossible to display in the
 * provider's own terms. Use normalisedRating() when mixing providers.
 */
final readonly class CollectedReview
{
    /**
     * @param  array<string, mixed>  $raw  The provider's untouched payload, for
     *                                     fields we have not modelled yet.
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public float $rating,
        public float $ratingScale = 10.0,
        public ?string $authorName = null,
        public ?string $city = null,
        public ?string $title = null,
        public ?string $body = null,
        public ?string $locale = null,
        public ?CarbonImmutable $reviewedAt = null,
        public bool $verified = false,
        /** Matches InvitationProduct::$identifier when the review is product level. */
        public ?string $productIdentifier = null,
        public ?string $gtin = null,
        public ?string $response = null,
        public ?CarbonImmutable $respondedAt = null,
        public array $raw = [],
    ) {}

    /**
     * The rating as 0.0 to 1.0, so reviews from providers with different
     * scales can be averaged together meaningfully.
     */
    public function normalisedRating(): float
    {
        if ($this->ratingScale <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->rating / $this->ratingScale));
    }

    public function hasResponse(): bool
    {
        return $this->response !== null && trim($this->response) !== '';
    }
}
