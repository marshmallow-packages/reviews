<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Data;

/**
 * The outcome of publishing a reply. Mirrors InvitationResult: returned rather
 * than thrown, and safe to log.
 */
final readonly class ResponseResult
{
    private function __construct(
        public string $provider,
        public bool $published,
        public ?string $message = null,
        public ?int $status = null,
    ) {}

    public static function published(string $provider): self
    {
        return new self($provider, true);
    }

    public static function failed(string $provider, string $message, ?int $status = null): self
    {
        return new self($provider, false, $message, $status);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'published' => $this->published,
            'message' => $this->message,
            'status' => $this->status,
        ];
    }
}
