<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Log\LogManager;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Reviews;
use Marshmallow\Reviews\Support\ConfigValue;
use Marshmallow\Reviews\Support\ExceptionReporter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands one invitation to one provider, or to every active provider.
 *
 * This job never throws. A review invitation is the least important thing
 * happening around a checkout, so a provider outage must not fail a job, must
 * not retry a fan-out that half succeeded, and must not fill failed_jobs with
 * noise nobody acts on. Everything that goes wrong is logged and swallowed.
 */
final class SendReviewInvitation implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ReviewInvitation $invitation,
        /** Null fans out to every active provider. */
        public readonly ?string $provider = null,
    ) {
        $connection = $this->queueConfig('connection');
        $queue = $this->queueConfig('queue');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(Reviews $reviews, Repository $config, LogManager $log): void
    {
        try {
            foreach ($this->resolveResults($reviews) as $result) {
                $this->logResult($config, $log, $result);
            }
        } catch (Throwable $exception) {
            $this->logThrowable($config, $log, $exception);
        }
    }

    /**
     * Read by the queue at dispatch time. Only serialisation failures can get
     * this far, since handle() swallows everything else.
     */
    public function tries(): int
    {
        $tries = $this->queueConfig('tries');

        if (is_int($tries) && $tries > 0) {
            return $tries;
        }

        if (is_string($tries) && ctype_digit($tries) && (int) $tries > 0) {
            return (int) $tries;
        }

        return 3;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = $this->queueConfig('backoff');

        if (is_int($backoff) && $backoff > 0) {
            return [$backoff];
        }

        if (is_array($backoff)) {
            $seconds = array_values(array_filter(
                array_map(static fn (mixed $value): ?int => is_int($value) && $value > 0 ? $value : null, $backoff),
                static fn (?int $value): bool => $value !== null,
            ));

            if ($seconds !== []) {
                return $seconds;
            }
        }

        return [60, 300, 900];
    }

    /**
     * @return list<InvitationResult>
     */
    private function resolveResults(Reviews $reviews): array
    {
        if ($this->provider === null) {
            return $reviews->inviteAll($this->invitation);
        }

        $provider = $reviews->driver($this->provider);

        if (! $provider instanceof SendsInvitations) {
            return [InvitationResult::skipped($provider->name(), SkipReason::ClientSideOnly)];
        }

        return [$provider->invite($this->invitation)];
    }

    /**
     * A skip is a correct outcome, so it stays as quiet as a success unless the
     * site asked for the detail.
     */
    private function logResult(Repository $config, LogManager $log, InvitationResult $result): void
    {
        $context = array_merge($result->context(), $this->invitation->context());

        if ($result->hasFailed()) {
            $this->write($config, $log, 'warning', 'Review invitation was not sent.', $context);

            return;
        }

        if ($this->logsSuccesses($config)) {
            $this->write($config, $log, 'info', 'Review invitation handled.', $context);
        }
    }

    /**
     * The exception message is deliberately left out of the log. A provider's
     * own message is redacted by InvitationResult, an arbitrary Throwable is
     * not: an HTTP client that echoes the request body would put the email
     * address straight into the log file.
     *
     * The full exception still goes to Sentry, which is access controlled and
     * where someone actually looks. Without that, a job that never rethrows
     * and never logs a message made real bugs invisible.
     */
    private function logThrowable(Repository $config, LogManager $log, Throwable $exception): void
    {
        if (ConfigValue::bool($config->get('reviews.log.report_exceptions', true))) {
            Container::getInstance()->make(ExceptionReporter::class)->report($exception);
        }

        $this->write(
            $config,
            $log,
            'warning',
            'Review invitation threw '.$exception::class.'.',
            array_merge($this->invitation->context(), [
                // The class alone leaves nothing to go on. A file and line are
                // ours, not the customer's, so they are safe to record and are
                // usually enough to find the fault without the message.
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]),
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function write(Repository $config, LogManager $log, string $level, string $message, array $context): void
    {
        try {
            $this->channel($config, $log)->log($level, $message, $context);
        } catch (Throwable) {
            // A misconfigured log channel is not worth failing an invitation over.
        }
    }

    private function channel(Repository $config, LogManager $log): LoggerInterface
    {
        $channel = $config->get('reviews.log.channel');

        return $log->channel(is_string($channel) && $channel !== '' ? $channel : null);
    }

    private function logsSuccesses(Repository $config): bool
    {
        return ConfigValue::bool($config->get('reviews.log.successes', false));
    }

    /**
     * The queue reads connection, queue, tries and backoff before the container
     * ever injects anything into this job, so these few values cannot come from
     * an injected repository the way handle() gets one.
     */
    private function queueConfig(string $key): mixed
    {
        try {
            return Container::getInstance()->make(Repository::class)->get('reviews.queue.'.$key);
        } catch (Throwable) {
            return null;
        }
    }
}
