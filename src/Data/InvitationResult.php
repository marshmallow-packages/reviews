<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

use Marshmallow\Reviews\Enums\InvitationOutcome;
use Marshmallow\Reviews\Enums\SkipReason;

/**
 * The outcome of one provider handling one invitation.
 *
 * Returned rather than thrown, because a provider being unconfigured, or
 * refusing a duplicate, or being briefly unreachable are all ordinary states
 * that a queued job should record and move past, not exceptions that fail the
 * job and retry the whole fan-out.
 */
final readonly class InvitationResult
{
    private function __construct(
        public string $provider,
        public InvitationOutcome $outcome,
        public ?SkipReason $skipReason = null,
        /** The provider's own identifier for the invitation, when it returns one. */
        public ?string $reference = null,
        /** Never contains customer data: it is written to logs verbatim. */
        public ?string $message = null,
        public ?int $status = null,
    ) {}

    public static function sent(string $provider, ?string $reference = null): self
    {
        return new self($provider, InvitationOutcome::Sent, reference: $reference);
    }

    public static function skipped(string $provider, SkipReason $reason): self
    {
        return new self($provider, InvitationOutcome::Skipped, skipReason: $reason, message: $reason->label());
    }

    public static function failed(string $provider, string $message, ?int $status = null): self
    {
        return new self($provider, InvitationOutcome::Failed, message: self::redact($message), status: $status);
    }

    /**
     * The failure message is the one field a provider fills in freely, and the
     * job writes it to a log verbatim. Redacting here rather than trusting each
     * provider is what makes "we never log an email address" a property of the
     * package instead of a habit: a provider registered through extend() is
     * somebody else's code, and a provider that echoes the address it rejected
     * back at us is normal behaviour, not a bug on their side.
     */
    private static function redact(string $message): string
    {
        $redacted = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email redacted]', $message) ?? $message;

        return mb_substr($redacted, 0, 500);
    }

    public function wasSent(): bool
    {
        return $this->outcome === InvitationOutcome::Sent;
    }

    public function wasSkipped(): bool
    {
        return $this->outcome === InvitationOutcome::Skipped;
    }

    public function hasFailed(): bool
    {
        return $this->outcome === InvitationOutcome::Failed;
    }

    /**
     * Safe for logging. There is no email address, name or city in here by
     * construction, which is what keeps the "never log personal data" rule
     * enforceable rather than aspirational.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'outcome' => $this->outcome->value,
            'skip_reason' => $this->skipReason?->value,
            'reference' => $this->reference,
            'message' => $this->message,
            'status' => $this->status,
        ];
    }
}
