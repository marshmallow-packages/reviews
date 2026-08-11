<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Support;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Sends an unexpected exception to the application's error tracker while
 * keeping it out of the log file.
 *
 * The package refuses to log an exception message, because a Throwable is not
 * ours: an HTTP client that echoes the request body puts the customer's email
 * address straight into a log file that gets shipped, rotated and read by
 * whoever has server access. Combined with a job that never rethrows, that
 * left genuine bugs completely invisible.
 *
 * Sentry is the right sink for the full exception: it is access controlled,
 * it has its own scrubbing, and it is where someone actually looks. So the
 * exception goes there in full and the log line stays redacted.
 *
 * Deliberately not Laravel's ExceptionHandler::report(). That writes the
 * message to the log channel as well, which is the leak this exists to avoid.
 */
final readonly class ExceptionReporter
{
    public function __construct(private Container $container) {}

    public function report(Throwable $exception): void
    {
        try {
            if (! $this->container->bound('sentry')) {
                return;
            }

            $sentry = $this->container->make('sentry');

            /*
             * Duck typed on purpose. Depending on sentry/sentry-laravel would
             * make an error tracker a hard requirement of collecting reviews,
             * and the binding is a HubInterface whose class we must not name
             * without that dependency.
             */
            if (is_object($sentry) && method_exists($sentry, 'captureException')) {
                $sentry->captureException($exception);
            }
        } catch (Throwable) {
            // Reporting a failure must never become a failure. An invitation
            // is the least important thing happening around a checkout, and
            // that goes double for its telemetry.
        }
    }
}
