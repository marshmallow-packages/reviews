<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors marshmallow/dataset-country: the ISO code lives on alpha2.
 */
class TestCountry extends Model
{
    protected $guarded = [];
}
