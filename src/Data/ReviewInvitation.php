<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

/**
 * Everything a provider might need to invite one customer.
 *
 * Deliberately holds scalars and a list of scalars only, no Eloquent models.
 * That is what lets the queued job serialise it without SerializesModels and
 * without a database round trip on the worker, and it means a soft deleted or
 * mutated order cannot change the contents of an invitation already in flight.
 */
final readonly class ReviewInvitation
{
    /**
     * @param  list<InvitationProduct>  $products
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $email,
        public string $orderReference,
        public ?string $firstName = null,
        public ?string $lastName = null,
        /** ISO-639-1. Providers map this to their own vocabulary. */
        public ?string $locale = null,
        /** ISO-3166-1 alpha-2. */
        public ?string $countryCode = null,
        public ?string $city = null,
        public ?CarbonImmutable $estimatedDeliveryDate = null,
        /** Null lets the provider fall back to its configured default. */
        public ?int $delayInDays = null,
        public ?int $orderTotalInCents = null,
        public array $products = [],
        /** Provider specific extras, passed through untouched. */
        public array $metadata = [],
    ) {}

    public static function fromOrder(ReviewableOrder $order): self
    {
        return new self(
            email: $order->reviewerEmail() ?? '',
            orderReference: $order->reviewOrderReference(),
            firstName: $order->reviewerFirstName(),
            lastName: $order->reviewerLastName(),
            locale: $order->reviewLocale(),
            countryCode: $order->reviewCountryCode(),
            city: $order->reviewCity(),
            estimatedDeliveryDate: $order->reviewEstimatedDeliveryDate(),
            orderTotalInCents: $order->reviewOrderTotalInCents(),
            products: $order->reviewProducts(),
        );
    }

    /**
     * An order without a customer email cannot be invited. Providers check
     * this rather than posting an empty address and reading back a validation
     * error.
     */
    public function hasEmail(): bool
    {
        return trim($this->email) !== '';
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * @return list<string>
     */
    public function gtins(): array
    {
        return array_values(array_filter(
            array_map(static fn (InvitationProduct $product): ?string => $product->gtin, $this->products),
            static fn (?string $gtin): bool => $gtin !== null && $gtin !== '',
        ));
    }

    /**
     * Safe for logging: the email address, the customer name and the city are
     * all left out. Everything this package writes to a log goes through a
     * method like this one.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [
            'order_reference' => $this->orderReference,
            'locale' => $this->locale,
            'country_code' => $this->countryCode,
            'products' => count($this->products),
        ];
    }
}
