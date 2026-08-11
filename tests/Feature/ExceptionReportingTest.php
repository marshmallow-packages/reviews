<?php

declare(strict_types=1);

use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Support\ExceptionReporter;

/**
 * The job never rethrows and never logs an exception message, which is correct
 * for a log file but left genuine bugs with nowhere to surface. The full
 * exception goes to Sentry instead, where the audience is developers rather
 * than anyone with server access.
 */
function fakeSentry(): object
{
    return new class
    {
        /**
         * @var list<Throwable>
         */
        public array $captured = [];

        public function captureException(Throwable $exception): void
        {
            $this->captured[] = $exception;
        }
    };
}

beforeEach(function (): void {
    $this->sentry = fakeSentry();

    app()->instance('sentry', $this->sentry);
    app()->forgetInstance(ExceptionReporter::class);
});

it('reports a throwing provider to sentry while keeping it out of the log', function (): void {
    $handler = captureReviewLog();

    reviewManager()->extend('exploding', fn (): SendsInvitations => explodingProvider());

    config()->set('reviews.active', ['exploding']);

    (new SendReviewInvitation(makeInvitation()))->handle(reviewManager(), app('config'), app('log'));

    expect($this->sentry->captured)->toHaveCount(1)
        ->and($this->sentry->captured[0])->toBeInstanceOf(Throwable::class);

    // The log still carries no exception message, only the redacted result.
    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->message)->toBe('Review invitation was not sent.')
        ->and($records[0]->context)->not->toHaveKey('exception_message');
});

it('reports nothing when exception reporting is switched off', function (): void {
    captureReviewLog();

    reviewManager()->extend('exploding', fn (): SendsInvitations => explodingProvider());

    config()->set('reviews.active', ['exploding']);
    config()->set('reviews.log.report_exceptions', false);

    (new SendReviewInvitation(makeInvitation()))->handle(reviewManager(), app('config'), app('log'));

    expect($this->sentry->captured)->toBeEmpty();
});

it('does not fail when sentry is not installed', function (): void {
    app()->forgetInstance('sentry');
    app()->offsetUnset('sentry');

    captureReviewLog();

    reviewManager()->extend('exploding', fn (): SendsInvitations => explodingProvider());

    config()->set('reviews.active', ['exploding']);

    $results = reviewManager()->inviteAll(makeInvitation());

    expect($results)->toHaveCount(1)
        ->and($results[0]->hasFailed())->toBeTrue();
});
