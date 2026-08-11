<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirrors marshmallow/cart's OrderItem. A line with no product is how the cart
 * stores shipping, which is why product_id is nullable here too.
 */
class TestOrderItem extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<TestProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(TestProduct::class, 'product_id');
    }
}
