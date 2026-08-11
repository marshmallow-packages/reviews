<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Tests\Fixtures;

use Marshmallow\Reviews\Contracts\ProvidesReviewLink;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Support\Html;

/**
 * A provider a site could plausibly write itself, used to prove that
 * Reviews::extend() makes a custom provider a first class citizen: resolvable
 * by name, eligible for the fan-out, and matched by supporting().
 *
 * It is also what the override tests register under 'kiyoh', to show a bundled
 * provider can be replaced without subclassing the manager.
 */
final class RecordingProvider implements ProvidesReviewLink, RendersBadge, SendsInvitations
{
    /**
     * @var list<ReviewInvitation>
     */
    public array $invitations = [];

    public function __construct(
        private readonly string $providerName = 'recording',
        private readonly bool $configured = true,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function invite(ReviewInvitation $invitation): InvitationResult
    {
        $this->invitations[] = $invitation;

        return InvitationResult::sent($this->providerName, $invitation->orderReference);
    }

    public function badge(): Html
    {
        return new Html('<span class="recording-badge"></span>');
    }

    public function reviewLink(?ReviewInvitation $invitation = null): string
    {
        return 'https://example.test/reviews';
    }
}
