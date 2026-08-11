<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

/**
 * One product on an invitation, for providers that collect product reviews
 * rather than only seller reviews.
 */
final readonly class InvitationProduct
{
    public function __construct(
        /** Our own identifier, usually the product id or SKU. */
        public string $identifier,
        /** GTIN or EAN. Google needs this for product level ratings. */
        public ?string $gtin = null,
        public ?string $name = null,
        public ?string $url = null,
        public ?string $imageUrl = null,
        public ?int $priceInCents = null,
        /**
         * Kiyoh's invite API takes bare product codes and ignores this, but
         * WebwinkelKeur's order_data payload carries a quantity per line, so it
         * is modelled here rather than added in a later breaking change.
         */
        public ?int $quantity = null,
    ) {}
}
