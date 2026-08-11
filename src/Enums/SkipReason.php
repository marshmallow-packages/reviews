<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Enums;

/**
 * Why an invitation was not sent, when not sending it was correct.
 *
 * A skip is not a failure. Monitoring needs to tell "this provider renders in
 * the browser instead" apart from "the provider returned a 500", because the
 * first is the system working and the second is not.
 */
enum SkipReason: string
{
    /** No API token, merchant id or location id. */
    case NotConfigured = 'not_configured';

    /** The provider has no invitation API and renders client side instead. */
    case ClientSideOnly = 'client_side_only';

    /** The provider refused a repeat invitation, as Kiyoh does within 30 days. */
    case Duplicate = 'duplicate';

    /** The order has no customer email address. */
    case NoEmail = 'no_email';

    /** Switched off in config, or the null provider. */
    case Disabled = 'disabled';

    /** A field this provider requires was missing from the invitation. */
    case MissingData = 'missing_data';

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Provider is not configured',
            self::ClientSideOnly => 'Provider renders client side instead of sending',
            self::Duplicate => 'Provider rejected a duplicate invitation',
            self::NoEmail => 'Order has no customer email address',
            self::Disabled => 'Provider is disabled',
            self::MissingData => 'Invitation is missing a field this provider requires',
        };
    }
}
