<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Data\InvitationProduct;

/**
 * What this package needs to know about an order to invite its customer.
 *
 * Nothing here requires marshmallow/cart. The DerivesReviewableOrder trait
 * satisfies the whole interface against our e-commerce models, but a project
 * with a different shape (yardy.nl invites on a Lead, not an Order) can
 * implement it directly.
 *
 * Every method may return null except the order reference, because a provider
 * decides for itself which of these it needs and reports a skip when something
 * required is missing. Guessing a value here would push the failure to the
 * provider's API instead of surfacing it locally.
 */
interface ReviewableOrder
{
    public function reviewerEmail(): ?string;

    public function reviewerFirstName(): ?string;

    public function reviewerLastName(): ?string;

    /**
     * The reference echoed back by the provider, so a review can be traced to
     * an order. Required: an invitation without one cannot be reconciled.
     */
    public function reviewOrderReference(): string;

    /**
     * ISO-639-1. Providers with their own locale vocabulary map from this.
     */
    public function reviewLocale(): ?string;

    /**
     * ISO-3166-1 alpha-2.
     */
    public function reviewCountryCode(): ?string;

    public function reviewCity(): ?string;

    /**
     * Google will not render its opt-in without this, and it decides when the
     * survey is sent. There is no such concept in marshmallow/cart, so it
     * comes from the site: either this method, or the estimated_delivery_date
     * resolver in config.
     */
    public function reviewEstimatedDeliveryDate(): ?CarbonImmutable;

    public function reviewOrderTotalInCents(): ?int;

    /**
     * @return list<InvitationProduct>
     */
    public function reviewProducts(): array;
}
