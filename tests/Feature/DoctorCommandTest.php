<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Contracts\ReviewableOrder;

/**
 * A site that has answered both questions the doctor asks about: who may render
 * in the browser, and when the parcel arrives.
 */
function answerBothQuestions(): void
{
    config()->set([
        'reviews.consent' => static fn (): bool => true,
        'reviews.estimated_delivery_date' => static fn (ReviewableOrder $order): CarbonImmutable => CarbonImmutable::now(),
    ]);
}

it('reports a healthy setup and exits successfully', function (): void {
    answerBothQuestions();

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('Everything checks out')
        ->assertExitCode(0);
});

it('lists the active providers and what they can do', function (): void {
    configureKiyoh(['reviews.active' => ['kiyoh', 'google']]);

    configureGoogle(['reviews.active' => ['kiyoh', 'google']]);

    answerBothQuestions();

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('kiyoh')
        ->expectsOutputToContain('google')
        ->assertExitCode(0);
});

/*
 * The silent failure the command exists for: Google active with nothing to tell
 * it when the parcel arrives renders no module at all, on a site that otherwise
 * looks correctly configured.
 */
it('fails when google is active without an estimated delivery date resolver', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    config()->set([
        'reviews.consent' => static fn (): bool => true,
        'reviews.estimated_delivery_date' => null,
    ]);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('problem(s) need attention')
        ->assertExitCode(1);
});

it('does not mind a missing delivery date resolver while google is not active', function (): void {
    configureKiyoh(['reviews.active' => ['kiyoh']]);

    config()->set([
        'reviews.consent' => static fn (): bool => true,
        'reviews.estimated_delivery_date' => null,
    ]);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('Everything checks out')
        ->assertExitCode(0);
});

it('warns when a client side provider is active without a consent callback', function (): void {
    configureGoogle(['reviews.active' => ['google']]);

    config()->set([
        'reviews.consent' => null,
        'reviews.estimated_delivery_date' => static fn (ReviewableOrder $order): CarbonImmutable => CarbonImmutable::now(),
    ]);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('worth a look')
        ->doesntExpectOutputToContain('Everything checks out')
        ->assertExitCode(0);

    // The same setup with a consent callback is clean, which is what makes the
    // warning above about consent rather than about anything else.
    config()->set('reviews.consent', static fn (): bool => true);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('Everything checks out')
        ->assertExitCode(0);
});

it('warns when the master switch is off', function (): void {
    answerBothQuestions();

    config()->set('reviews.enabled', false);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('worth a look')
        ->assertExitCode(0);
});

it('reports a provider name that cannot be resolved as a problem', function (): void {
    answerBothQuestions();

    config()->set('reviews.active', ['trustpilot']);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('problem(s) need attention')
        ->assertExitCode(1);
});

it('warns when event listening is on with nothing bound to it', function (): void {
    answerBothQuestions();

    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.listen' => [],
    ]);

    $this->artisan('reviews:doctor')
        ->expectsOutputToContain('worth a look')
        ->assertExitCode(0);
});

it('changes nothing it looks at', function (): void {
    answerBothQuestions();

    configureKiyoh(['reviews.active' => ['kiyoh']]);

    $before = config('reviews');

    $this->artisan('reviews:doctor')->assertExitCode(0);
    $this->artisan('reviews:doctor')->assertExitCode(0);

    expect(config('reviews'))->toBe($before);
});
