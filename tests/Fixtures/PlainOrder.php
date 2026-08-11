<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Data\InvitationProduct;

/**
 * The yardy.nl case: a project not on marshmallow/cart, implementing the
 * interface directly on whatever model it does have. Nothing Eloquent, nothing
 * from the cart package. If this stops compiling, the package has grown a
 * dependency on our e-commerce stack that the contract promised it would not.
 */
final class PlainOrder implements ReviewableOrder
{
    /**
     * @param  list<InvitationProduct>  $products
     */
    public function __construct(
        private readonly ?string $email = 'klant@example.test',
        private readonly string $reference = 'PLAIN-1',
        private readonly ?string $firstName = 'Sanne',
        private readonly ?string $lastName = 'de Vries',
        private readonly ?string $locale = 'nl',
        private readonly ?string $countryCode = 'NL',
        private readonly ?string $city = 'Utrecht',
        private readonly ?CarbonImmutable $deliveryDate = null,
        private readonly ?int $total = 4995,
        private readonly array $products = [],
    ) {}

    public function reviewerEmail(): ?string
    {
        return $this->email;
    }

    public function reviewerFirstName(): ?string
    {
        return $this->firstName;
    }

    public function reviewerLastName(): ?string
    {
        return $this->lastName;
    }

    public function reviewOrderReference(): string
    {
        return $this->reference;
    }

    public function reviewLocale(): ?string
    {
        return $this->locale;
    }

    public function reviewCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function reviewCity(): ?string
    {
        return $this->city;
    }

    public function reviewEstimatedDeliveryDate(): ?CarbonImmutable
    {
        return $this->deliveryDate;
    }

    public function reviewOrderTotalInCents(): ?int
    {
        return $this->total;
    }

    /**
     * @return list<InvitationProduct>
     */
    public function reviewProducts(): array
    {
        return $this->products;
    }
}
