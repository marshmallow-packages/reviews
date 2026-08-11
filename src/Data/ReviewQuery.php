<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

use Carbon\CarbonImmutable;

/**
 * What to ask a provider for when importing. Every field is a hint: a provider
 * that cannot filter server side is free to ignore one, and the caller should
 * not assume the result is filtered.
 */
final readonly class ReviewQuery
{
    public function __construct(
        public ?int $limit = null,
        public ?CarbonImmutable $since = null,
        public ?string $locale = null,
        public ?string $productIdentifier = null,
    ) {}

    public static function all(): self
    {
        return new self;
    }

    public static function since(CarbonImmutable $since): self
    {
        return new self(since: $since);
    }

    public function forProduct(string $identifier): self
    {
        return new self(
            limit: $this->limit,
            since: $this->since,
            locale: $this->locale,
            productIdentifier: $identifier,
        );
    }
}
