<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

use Carbon\CarbonImmutable;

/**
 * A provider's own published aggregate. Kept separate from a locally computed
 * average, because the number a provider shows on its badge is the number a
 * customer will compare against and it is not always the mean of the reviews
 * the API hands back.
 */
final readonly class ReviewSummary
{
    public function __construct(
        public string $provider,
        public float $average,
        public float $scale,
        public int $count,
        public ?CarbonImmutable $fetchedAt = null,
    ) {}

    /**
     * The average as 0.0 to 1.0, for comparing across providers with
     * different scales.
     */
    public function normalisedAverage(): float
    {
        if ($this->scale <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->average / $this->scale));
    }

    /**
     * Rescaled to another maximum, for showing a Kiyoh score out of 10 as
     * stars out of 5.
     */
    public function averageOutOf(float $scale): float
    {
        return $this->normalisedAverage() * $scale;
    }
}
