<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marshmallow\Reviews\Concerns\DerivesReviewableOrder;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

/**
 * Shaped like marshmallow/cart's Order, including the trap that matters:
 * shippingAddress() there is not an Eloquent relation, it is a plain method
 * running its own query and returning a Model. Reading $order->shippingAddress
 * on such a model makes Eloquent throw LogicException, so the trait has to fall
 * back to calling the method.
 *
 * There is deliberately NO $shippingAddress property. An earlier version of
 * this fixture declared one as private, and because the trait is composed into
 * this class its property read resolved that private property directly: the
 * LogicException never fired, the method fallback was never reached, and the
 * test passed while every real cart site would have fataled. The address is
 * therefore held in a differently named property, exactly as the cart model
 * holds no property at all.
 */
class TestOrder extends Model implements ReviewableOrder
{
    use DerivesReviewableOrder;

    protected $guarded = [];

    /**
     * Named so that nothing can resolve it as the "shippingAddress" attribute.
     */
    private ?TestAddress $addressForShipping = null;

    /**
     * @return BelongsTo<TestCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(TestCustomer::class, 'customer_id');
    }

    /**
     * Not a relation, on purpose. Eloquent rejects this when read as a
     * property, which is the whole point of the fixture.
     */
    public function shippingAddress(): ?TestAddress
    {
        return $this->addressForShipping;
    }

    public function withShippingAddress(?TestAddress $address): self
    {
        $this->addressForShipping = $address;

        return $this;
    }
}
