<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Illuminate\Contracts\Support\Renderable;

/**
 * SHOW. The provider offers a score badge or seal that can sit anywhere on the
 * site, independent of any single order.
 */
interface RendersBadge extends ReviewProvider
{
    /**
     * Null when the provider has nothing to show, which is the normal state
     * for a site that has not configured it.
     */
    public function badge(): ?Renderable;
}
