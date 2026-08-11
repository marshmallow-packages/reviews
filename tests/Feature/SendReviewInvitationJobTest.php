<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Tests\Fixtures\RecordingProvider;
use Monolog\LogRecord;

/**
 * A provider that fails politely, the way a real one reports an outage.
 */
function failingProvider(): SendsInvitations
{
    return new class implements SendsInvitations
    {
        public function name(): string
        {
            return 'failing';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function invite(ReviewInvitation $invitation): InvitationResult
        {
            return InvitationResult::failed($this->name(), 'The provider returned an error.', 503);
        }
    };
}

it('pushes the job onto the queue', function (): void {
    Queue::fake();

    SendReviewInvitation::dispatch(makeInvitation(orderReference: 'QUEUED-1'));

    Queue::assertPushed(
        SendReviewInvitation::class,
        static fn (SendReviewInvitation $job): bool => $job->invitation->orderReference === 'QUEUED-1'
            && $job->provider === null,
    );
});

it('reads its tries and backoff out of config', function (): void {
    config()->set([
        'reviews.queue.tries' => 5,
        'reviews.queue.backoff' => [10, 20],
    ]);

    $job = new SendReviewInvitation(makeInvitation());

    expect($job->tries())->toBe(5)
        ->and($job->backoff())->toBe([10, 20]);
});

it('fans out to every active provider when no provider is named', function (): void {
    $recording = new RecordingProvider;

    reviewManager()->extend('recording', fn (): RecordingProvider => $recording);

    config()->set('reviews.active', ['recording', 'null']);

    (new SendReviewInvitation(makeInvitation(orderReference: 'FANOUT-1')))->handle(
        reviewManager(),
        app('config'),
        app('log'),
    );

    expect($recording->invitations)->toHaveCount(1)
        ->and($recording->invitations[0]->orderReference)->toBe('FANOUT-1');
});

it('hands the invitation to one named provider only', function (): void {
    $recording = new RecordingProvider;
    $other = new RecordingProvider('other');

    reviewManager()->extend('recording', fn (): RecordingProvider => $recording);
    reviewManager()->extend('other', fn (): RecordingProvider => $other);

    config()->set('reviews.active', ['recording', 'other']);

    (new SendReviewInvitation(makeInvitation(), 'recording'))->handle(
        reviewManager(),
        app('config'),
        app('log'),
    );

    expect($recording->invitations)->toHaveCount(1)
        ->and($other->invitations)->toBe([]);
});

it('reports a named provider that cannot send as a client side only skip', function (): void {
    $handler = captureReviewLog();

    configureGoogle();

    config()->set('reviews.log.successes', true);

    (new SendReviewInvitation(makeInvitation(), 'google'))->handle(
        reviewManager(),
        app('config'),
        app('log'),
    );

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->context['provider'])->toBe('google')
        ->and($records[0]->context['skip_reason'])->toBe(SkipReason::ClientSideOnly->value);
});

it('does not fail the job when a provider throws', function (): void {
    $handler = captureReviewLog();

    reviewManager()->extend('exploding', fn (): SendsInvitations => explodingProvider());

    config()->set('reviews.active', ['exploding']);

    $job = new SendReviewInvitation(makeInvitation(orderReference: 'BOOM-1'));

    $job->handle(reviewManager(), app('config'), app('log'));

    $records = $handler->getRecords();

    // The manager converts a throwing provider into a failed result rather
    // than letting it escape, so the exception class arrives in the result
    // message and the job logs its ordinary failure line. The job's own
    // Throwable catch is still there, one layer further out.
    expect($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe('WARNING')
        ->and($records[0]->message)->toBe('Review invitation was not sent.')
        ->and($records[0]->context['message'])->toContain('RuntimeException')
        ->and($records[0]->context['order_reference'])->toBe('BOOM-1');
});

it('logs a failure at warning level', function (): void {
    $handler = captureReviewLog();

    reviewManager()->extend('failing', fn (): SendsInvitations => failingProvider());

    config()->set('reviews.active', ['failing']);

    (new SendReviewInvitation(makeInvitation()))->handle(reviewManager(), app('config'), app('log'));

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe('WARNING')
        ->and($records[0]->message)->toBe('Review invitation was not sent.')
        ->and($records[0]->context['status'])->toBe(503);
});

it('stays quiet about a success unless the site asked for it', function (): void {
    $handler = captureReviewLog();

    reviewManager()->extend('recording', fn (): RecordingProvider => new RecordingProvider);

    config()->set('reviews.active', ['recording']);

    (new SendReviewInvitation(makeInvitation()))->handle(reviewManager(), app('config'), app('log'));

    expect($handler->getRecords())->toBe([]);

    config()->set('reviews.log.successes', true);

    (new SendReviewInvitation(makeInvitation()))->handle(reviewManager(), app('config'), app('log'));

    $records = $handler->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe('INFO')
        ->and($records[0]->message)->toBe('Review invitation handled.');
});

/*
 * The hard rule: nothing this package writes to a log file identifies the
 * customer. That has to hold for the provider's own message, for the exception
 * path, and for the success path alike.
 */
it('never writes the email address, the name or the city to the log', function (string $providerName): void {
    $handler = captureReviewLog();

    reviewManager()->extend($providerName, static fn (): SendsInvitations => match ($providerName) {
        'exploding' => explodingProvider(),
        'failing' => failingProvider(),
        default => new RecordingProvider,
    });

    config()->set([
        'reviews.active' => [$providerName],
        'reviews.log.successes' => true,
    ]);

    (new SendReviewInvitation(makeInvitation(
        email: 'klant@example.test',
        firstName: 'Sanne',
        lastName: 'de Vries',
        city: 'Utrecht',
    )))->handle(reviewManager(), app('config'), app('log'));

    $records = $handler->getRecords();

    expect($records)->not->toBe([]);

    $written = json_encode(array_map(
        static fn (LogRecord $record): array => [$record->message, $record->context],
        $records,
    ), JSON_THROW_ON_ERROR);

    expect($written)->not->toContain('klant@example.test')
        ->not->toContain('example.test')
        ->not->toContain('Sanne')
        ->not->toContain('de Vries')
        ->not->toContain('Utrecht');
})->with([
    'a provider that throws' => 'exploding',
    'a provider that fails' => 'failing',
    'a provider that succeeds' => 'recording',
]);
