<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Marshmallow\Reviews\Data\InvitationProduct;
use Throwable;

/**
 * Satisfies ReviewableOrder against marshmallow/cart conventions, so a site on
 * our e-commerce stack integrates by adding one trait to its Order model.
 *
 * marshmallow/cart is a suggest, never a require. Nothing here type hints a
 * cart class, nothing here assumes a relation or a column exists, and every
 * derivation degrades to null instead of erroring. That is what lets yardy.nl
 * put this same trait on a Lead that has no customer relation, no addresses
 * and no order items.
 *
 * Column and relation names come from config('reviews.order.*'), so a site
 * whose schema differs adjusts config rather than overriding methods. Every
 * method is still a plain public method: a model that knows better simply
 * declares its own and the trait method steps aside.
 */
trait DerivesReviewableOrder
{
    public function reviewerEmail(): ?string
    {
        return $this->reviewCustomerString('email_column', 'email');
    }

    public function reviewerFirstName(): ?string
    {
        return $this->reviewCustomerString('first_name_column', 'first_name');
    }

    public function reviewerLastName(): ?string
    {
        return $this->reviewCustomerString('last_name_column', 'last_name');
    }

    /**
     * The primary key is the fallback because an order always has one, while
     * shopping_cart_display_id is only filled once a cart has been converted.
     */
    public function reviewOrderReference(): string
    {
        $reference = $this->reviewString($this, $this->reviewOrderKey('reference_column', 'shopping_cart_display_id'));

        if ($reference !== null) {
            return $reference;
        }

        return $this->reviewModelKey($this) ?? '';
    }

    public function reviewLocale(): ?string
    {
        return app()->getLocale();
    }

    public function reviewCountryCode(): ?string
    {
        $address = $this->reviewShippingAddress();

        if ($address === null) {
            return null;
        }

        $country = $this->reviewRelated($address, $this->reviewOrderKey('country_relation', 'country'));

        if (! is_object($country)) {
            return null;
        }

        return $this->reviewString($country, $this->reviewOrderKey('country_code_column', 'alpha2'));
    }

    public function reviewCity(): ?string
    {
        $address = $this->reviewShippingAddress();

        if ($address === null) {
            return null;
        }

        return $this->reviewString($address, $this->reviewOrderKey('city_column', 'city'));
    }

    public function reviewOrderTotalInCents(): ?int
    {
        return $this->reviewInt($this, $this->reviewOrderKey('total_column', 'price_including_vat'));
    }

    /**
     * Order lines without a product, such as the shipping line marshmallow/cart
     * stores alongside the real items, are left out: there is nothing to review.
     *
     * @return list<InvitationProduct>
     */
    public function reviewProducts(): array
    {
        $items = $this->reviewRelated($this, $this->reviewOrderKey('items_relation', 'items'));

        if (! is_iterable($items)) {
            return [];
        }

        $products = [];

        foreach ($items as $item) {
            if (! is_object($item)) {
                continue;
            }

            $product = $this->reviewProductFrom($item);

            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Null by default: marshmallow/cart has no delivery estimate, and guessing
     * one would mistime every Google survey without anyone noticing. The config
     * resolver is the site's chance to answer for all its orders at once, and
     * overriding this method is its chance to answer per order.
     */
    public function reviewEstimatedDeliveryDate(): ?CarbonImmutable
    {
        $resolver = config('reviews.estimated_delivery_date');

        if (! is_callable($resolver)) {
            return null;
        }

        try {
            return $this->reviewDate($resolver($this));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * All three reviewer fields hang off the same customer, and the relation is
     * read again per field rather than once: a model that lazy loads it has it
     * in memory after the first read, and one that cannot resolve it at all
     * yields null every time.
     */
    private function reviewCustomerString(string $key, string $default): ?string
    {
        $customer = $this->reviewRelated($this, $this->reviewOrderKey('customer_relation', 'customer'));

        if (! is_object($customer)) {
            return null;
        }

        return $this->reviewString($customer, $this->reviewOrderKey($key, $default));
    }

    /**
     * The one lookup that has to survive marshmallow/cart's non-relation
     * shippingAddress(), so both callers go through reviewRelated() rather than
     * reading the property.
     */
    private function reviewShippingAddress(): ?object
    {
        $address = $this->reviewRelated($this, $this->reviewOrderKey('shipping_address_relation', 'shippingAddress'));

        return is_object($address) ? $address : null;
    }

    private function reviewProductFrom(object $item): ?InvitationProduct
    {
        $product = $this->reviewRelated($item, $this->reviewOrderKey('product_relation', 'product'));

        if (! is_object($product)) {
            return null;
        }

        $identifier = $this->reviewModelKey($product);

        if ($identifier === null) {
            return null;
        }

        // A line that was zeroed out is still on the order but was never
        // delivered, so it does not belong on a review invitation.
        $quantity = $this->reviewInt($item, $this->reviewOrderKey('quantity_column', 'quantity'));

        if ($quantity !== null && $quantity < 1) {
            return null;
        }

        return new InvitationProduct(
            identifier: $identifier,
            gtin: $this->reviewString($product, $this->reviewOrderKey('gtin_column', 'ni')),
            name: $this->reviewString($product, $this->reviewOrderKey('product_name_column', 'name')),
            quantity: $quantity,
        );
    }

    private function reviewOrderKey(string $key, string $default): string
    {
        $value = config('reviews.order.'.$key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Reads a relation without assuming there is one. Property access covers
     * ordinary Eloquent relations, and the method call covers accessors that
     * return a model directly: marshmallow/cart's Order::shippingAddress() is
     * one of those, and Eloquent rejects it as a relation.
     */
    private function reviewRelated(object $subject, string $name): mixed
    {
        try {
            $value = isset($subject->{$name}) ? $subject->{$name} : null;
        } catch (Throwable) {
            $value = null;
        }

        if ($value === null && method_exists($subject, $name)) {
            try {
                $value = $subject->{$name}();
            } catch (Throwable) {
                return null;
            }
        }

        if ($value instanceof Relation) {
            try {
                return $value->getResults();
            } catch (Throwable) {
                return null;
            }
        }

        return $value;
    }

    /**
     * isset() rather than a bare read: a model without the column, or a plain
     * object that never had the property, must yield null instead of an
     * undefined property warning. Eloquent answers it through __isset, so a
     * real column or a loaded relation still comes back.
     */
    private function reviewAttribute(object $subject, string $key): mixed
    {
        try {
            return isset($subject->{$key}) ? $subject->{$key} : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function reviewString(object $subject, string $key): ?string
    {
        $value = $this->reviewAttribute($subject, $key);

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function reviewInt(object $subject, string $key): ?int
    {
        $value = $this->reviewAttribute($subject, $key);

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function reviewModelKey(object $subject): ?string
    {
        if (! method_exists($subject, 'getKey')) {
            return null;
        }

        try {
            $key = $subject->getKey();
        } catch (Throwable) {
            return null;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        if (is_string($key)) {
            $key = trim($key);

            return $key === '' ? null : $key;
        }

        return null;
    }

    private function reviewDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
