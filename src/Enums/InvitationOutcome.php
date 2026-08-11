<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Enums;

enum InvitationOutcome: string
{
    case Sent = 'sent';

    case Skipped = 'skipped';

    case Failed = 'failed';
}
