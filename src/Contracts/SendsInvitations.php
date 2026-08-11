<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;

/**
 * SEND. The provider accepts a review invitation over its own API, and mails
 * the customer itself. Kiyoh, WebwinkelKeur and Trustpilot work this way.
 */
interface SendsInvitations extends ReviewProvider
{
    /**
     * Never throws for an expected outcome. A provider that is unconfigured,
     * that rejects a duplicate, or that returns an error status reports it
     * through the result object so the caller can log it without a try/catch
     * and without an unhandled queue failure.
     */
    public function invite(ReviewInvitation $invitation): InvitationResult;
}
