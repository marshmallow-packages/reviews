<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirrors marshmallow/addressable: city on the address, the ISO code one hop
 * further along the country relation.
 */
class TestAddress extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<TestCountry, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(TestCountry::class);
    }
}
