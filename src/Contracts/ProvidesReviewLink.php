<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

use Marshmallow\Reviews\Data\ReviewInvitation;

/**
 * LINK. The provider gives us a URL and we mail it ourselves, from our own
 * transactional email rather than theirs.
 *
 * This is a third integration model alongside "we call their API" and "their
 * JavaScript renders on our page". Trustoo can only work this way, having no
 * invitation API at all, but Kiyoh, WebwinkelKeur and Trustpilot expose review
 * links too, so it is not a single provider's quirk.
 */
interface ProvidesReviewLink extends ReviewProvider
{
    /**
     * A URL the site can drop into its own email. The invitation is optional:
     * with one, a provider that supports per-order links can return a
     * pre-filled URL, without one it returns the generic profile link.
     *
     * Null when the provider is not configured.
     */
    public function reviewLink(?ReviewInvitation $invitation = null): ?string;
}
