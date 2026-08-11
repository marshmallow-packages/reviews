<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Marshmallow\Reviews\Contracts\SendsInvitations;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Data\InvitationResult;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Reviews;
use Marshmallow\Reviews\Tests\TestCase;
use Monolog\Handler\TestHandler;

uses(TestCase::class)->in(__DIR__);

/**
 * The manager as the application holds it. Resolved from the container rather
 * than through the facade, so a test that swaps the binding sees the swap.
 */
function reviewManager(): Reviews
{
    return app(Reviews::class);
}

/**
 * Give Kiyoh everything it needs to consider itself configured, and take the
 * retry sleep out so a retry test does not wait on real time.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureKiyoh(array $overrides = []): void
{
    config()->set([
        'reviews.default' => 'kiyoh',
        'reviews.active' => null,
        'reviews.providers.kiyoh.base_url' => 'https://www.klantenvertellen.nl',
        'reviews.providers.kiyoh.api_token' => 'test-api-token',
        'reviews.providers.kiyoh.location_id' => '1234567',
        'reviews.providers.kiyoh.locale' => 'nl',
        'reviews.providers.kiyoh.delay_days' => 3,
        'reviews.providers.kiyoh.skip_weekends' => false,
        'reviews.providers.kiyoh.profile_url' => 'https://www.klantenvertellen.nl/profile/1234567',
        'reviews.http.attempts' => 1,
        'reviews.http.retry_sleep_milliseconds' => 0,
    ]);

    config()->set($overrides);
}

/**
 * The language is pinned rather than left to the app locale, so the rendered
 * markup a snapshot is taken of does not move with the test application.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureGoogle(array $overrides = []): void
{
    config()->set([
        'reviews.default' => 'google',
        'reviews.active' => null,
        'reviews.providers.google.merchant_id' => '11223344',
        'reviews.providers.google.opt_in_style' => 'CENTER_DIALOG',
        'reviews.providers.google.badge_position' => 'BOTTOM_RIGHT',
        'reviews.providers.google.language' => 'nl',
    ]);

    config()->set($overrides);
}

/**
 * An invitation with every field filled, so a test only states what it changes.
 *
 * @param  list<InvitationProduct>  $products
 */
function makeInvitation(
    string $email = 'klant@example.test',
    string $orderReference = 'ORDER-1',
    ?string $firstName = 'Sanne',
    ?string $lastName = 'de Vries',
    ?string $locale = 'nl',
    ?string $countryCode = 'NL',
    ?string $city = 'Utrecht',
    ?CarbonImmutable $estimatedDeliveryDate = null,
    ?int $delayInDays = null,
    ?int $orderTotalInCents = 4995,
    array $products = [],
): ReviewInvitation {
    return new ReviewInvitation(
        email: $email,
        orderReference: $orderReference,
        firstName: $firstName,
        lastName: $lastName,
        locale: $locale,
        countryCode: $countryCode,
        city: $city,
        estimatedDeliveryDate: $estimatedDeliveryDate,
        delayInDays: $delayInDays,
        orderTotalInCents: $orderTotalInCents,
        products: $products,
    );
}

/**
 * Route the package's log lines into an in-memory Monolog handler, so a test
 * can read back exactly what would have hit the log file.
 */
function captureReviewLog(): TestHandler
{
    config()->set([
        'logging.channels.reviews-test' => [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ],
        'reviews.log.channel' => 'reviews-test',
    ]);

    $handler = Log::channel('reviews-test')->getLogger()->getHandlers()[0];

    expect($handler)->toBeInstanceOf(TestHandler::class);

    /** @var TestHandler $handler */
    return $handler;
}

/**
 * A provider whose invite() blows up, for the quiet failure contract.
 */
function explodingProvider(): SendsInvitations
{
    return new class implements SendsInvitations
    {
        public function name(): string
        {
            return 'exploding';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function invite(ReviewInvitation $invitation): InvitationResult
        {
            throw new RuntimeException('The provider SDK exploded with klant@example.test in the message.');
        }
    };
}
