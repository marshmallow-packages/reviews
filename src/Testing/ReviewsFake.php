<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Testing;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Facades\Reviews as ReviewsFacade;
use Marshmallow\Reviews\Reviews;
use Override;
use PHPUnit\Framework\Assert;

/**
 * Swapped in by ReviewsFake::swap(). Invitations are kept in memory instead of
 * reaching a provider, the result each send returns is scripted, and the
 * assertions read from there.
 *
 * A fake rather than a faked HTTP client, because "did this order get invited"
 * is the question a site actually has, and answering it through Http::fake()
 * would tie every test to one provider's request shape.
 */
final class ReviewsFake extends Reviews
{
    /**
     * The provider name every scripted result is attributed to. Not a real
     * driver: nothing resolves it, it only labels the result.
     */
    public const string PROVIDER = 'fake';

    /**
     * @var list<ReviewInvitation>
     */
    private array $invitations = [];

    private ?InvitationResult $result = null;

    /**
     * The container is optional so Reviews::fake() can construct the double
     * without knowing how the manager is built. Manager reads the config
     * repository out of whatever container it is handed, and inside a test that
     * is the application itself.
     */
    public function __construct(?Container $container = null)
    {
        parent::__construct($container ?? IlluminateContainer::getInstance());
    }

    /**
     * Bind the fake in place of the manager without going through the facade,
     * for a test that resolves the manager from the container itself. The
     * facade keeps its own resolved instance, so both are replaced or a later
     * Reviews::inviteAll() would still reach the real manager.
     */
    public function activate(?Container $container = null): self
    {
        ($container ?? IlluminateContainer::getInstance())->instance(Reviews::class, $this);

        ReviewsFacade::swap($this);

        return $this;
    }

    /**
     * Script the result every send returns from here on, so a test can play out
     * a provider outage without an HTTP layer to fake.
     */
    public function respondWith(InvitationResult $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function shouldFail(string $message = 'The provider rejected the invitation.', ?int $status = null): self
    {
        return $this->respondWith(InvitationResult::failed(self::PROVIDER, $message, $status));
    }

    /**
     * @return list<InvitationResult>
     */
    #[Override]
    public function inviteAll(ReviewInvitation $invitation): array
    {
        // The fake replaces the manager wholesale, so it has to honour the
        // master switch itself. Without this, a test asserting that nothing is
        // invited while REVIEWS_ENABLED is false would fail against the fake
        // and pass against the real manager, which is the worst way for a test
        // double to be wrong.
        if (! $this->enabled()) {
            return [];
        }

        $this->invitations[] = $invitation;

        return [$this->result()];
    }

    /**
     * The manager reaches its default driver through __call, which a fake with
     * no drivers cannot honour, so the single provider case is answered here.
     */
    public function invite(ReviewInvitation $invitation): InvitationResult
    {
        if (! $this->enabled()) {
            return InvitationResult::skipped(self::PROVIDER, SkipReason::Disabled);
        }

        $this->invitations[] = $invitation;

        return $this->result();
    }

    /**
     * @return list<ReviewInvitation>
     */
    public function invitations(): array
    {
        return $this->invitations;
    }

    /**
     * @param  (callable(ReviewInvitation): bool)|string|null  $callback  A string matches on the order reference.
     */
    public function assertInvited(callable|string|null $callback = null): self
    {
        $matches = $this->matching($callback);

        Assert::assertNotEmpty($matches, $this->describeExpected($callback));

        return $this;
    }

    /**
     * @param  (callable(ReviewInvitation): bool)|null  $callback
     */
    public function assertInvitedTimes(int $times, ?callable $callback = null): self
    {
        $matches = $this->matching($callback);

        Assert::assertCount(
            $times,
            $matches,
            sprintf(
                'Expected %d review invitation(s), but %d were recorded%s.',
                $times,
                count($matches),
                $this->describeReferences($matches),
            ),
        );

        return $this;
    }

    public function assertNothingInvited(): self
    {
        Assert::assertEmpty(
            $this->invitations,
            sprintf(
                'Expected no review invitation to be sent, but %d were recorded%s.',
                count($this->invitations),
                $this->describeReferences($this->invitations),
            ),
        );

        return $this;
    }

    /**
     * @param  (callable(ReviewInvitation): bool)|string  $callback  A string matches on the order reference.
     */
    public function assertNotInvited(callable|string $callback): self
    {
        $matches = $this->matching($callback);

        Assert::assertEmpty(
            $matches,
            is_string($callback)
                ? "Expected no review invitation for order [{$callback}], but one was recorded."
                : 'Expected no review invitation matching the given callback, but one was recorded.',
        );

        return $this;
    }

    private function result(): InvitationResult
    {
        return $this->result ??= InvitationResult::sent(self::PROVIDER);
    }

    /**
     * @param  (callable(ReviewInvitation): bool)|string|null  $callback
     * @return list<ReviewInvitation>
     */
    private function matching(callable|string|null $callback): array
    {
        if ($callback === null) {
            return $this->invitations;
        }

        $matcher = is_string($callback)
            ? static fn (ReviewInvitation $invitation): bool => $invitation->orderReference === $callback
            : $callback;

        return array_values(array_filter($this->invitations, $matcher));
    }

    /**
     * @param  (callable(ReviewInvitation): bool)|string|null  $callback
     */
    private function describeExpected(callable|string|null $callback): string
    {
        if ($callback === null) {
            return 'Expected a review invitation to be sent, but none was recorded.';
        }

        $subject = is_string($callback)
            ? "for order [{$callback}]"
            : 'matching the given callback';

        return sprintf(
            'Expected a review invitation %s, but none was recorded%s.',
            $subject,
            $this->describeReferences($this->invitations),
        );
    }

    /**
     * Order references only. Everything this package prints is free of email
     * addresses, customer names and cities, and a failing assertion is no
     * reason to make an exception.
     *
     * @param  list<ReviewInvitation>  $invitations
     */
    private function describeReferences(array $invitations): string
    {
        if ($invitations === []) {
            return '';
        }

        $references = array_map(
            static fn (ReviewInvitation $invitation): string => $invitation->orderReference,
            $invitations,
        );

        return ' for order(s) ['.implode(', ', $references).']';
    }
}
