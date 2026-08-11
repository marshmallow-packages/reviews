<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Illuminate\Contracts\Support\Renderable;
use Marshmallow\Reviews\Data\ReviewInvitation;

/**
 * SHOW. The provider has no server side invitation API and instead renders an
 * opt-in module in the browser on the order confirmation page. Google Customer
 * Reviews is the reason this interface exists.
 */
interface RendersOptIn extends ReviewProvider
{
    /**
     * Null when there is nothing to render: no credentials, or a required
     * field such as an estimated delivery date is missing. Returning null is
     * how a provider declines rather than emitting a snippet that will fail
     * silently in the visitor's browser.
     */
    public function optIn(ReviewInvitation $invitation): ?Renderable;
}
