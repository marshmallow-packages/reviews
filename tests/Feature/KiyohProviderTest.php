<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Enums\SkipReason;
use Marshmallow\Reviews\Providers\KiyohProvider;

/**
 * The provider under test, resolved the way the manager resolves it.
 */
function kiyoh(): KiyohProvider
{
    $provider = reviewManager()->driver('kiyoh');

    expect($provider)->toBeInstanceOf(KiyohProvider::class);

    /** @var KiyohProvider $provider */
    return $provider;
}

beforeEach(function (): void {
    configureKiyoh();
});

it('posts the invitation to the invite endpoint with the publication token', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $result = kiyoh()->invite(makeInvitation(
        orderReference: 'ORDER-42',
        products: [new InvitationProduct(identifier: '77', gtin: '0123456789012', name: 'Kussen')],
    ));

    expect($result->wasSent())->toBeTrue()
        ->and($result->provider)->toBe('kiyoh');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://www.klantenvertellen.nl/v1/invite/external')
            ->and($request->method())->toBe('POST')
            ->and($request->header('X-Publication-Api-Token'))->toBe(['test-api-token'])
            ->and($request->data())->toBe([
                'location_id' => '1234567',
                'invite_email' => 'klant@example.test',
                'delay' => 3,
                'first_name' => 'Sanne',
                'last_name' => 'de Vries',
                'language' => 'nl',
                'ref_code' => 'ORDER-42',
                'product_code' => ['77'],
                'city' => 'Utrecht',
            ]);

        return true;
    });
});

it('leaves the product code out when the order has no products', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    kiyoh()->invite(makeInvitation());

    Http::assertSent(function (Request $request): bool {
        expect($request->data())->not->toHaveKey('product_code');

        return true;
    });
});

it('skips without touching the network when it is not configured', function (): void {
    Http::fake();

    config()->set('reviews.providers.kiyoh.api_token', null);

    $result = kiyoh()->invite(makeInvitation());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::NotConfigured)
        ->and(kiyoh()->isConfigured())->toBeFalse();

    Http::assertNothingSent();
});

it('skips without touching the network when the location id is missing', function (): void {
    Http::fake();

    config()->set('reviews.providers.kiyoh.location_id', null);

    expect(kiyoh()->invite(makeInvitation())->skipReason)->toBe(SkipReason::NotConfigured);

    Http::assertNothingSent();
});

it('skips an invitation without an email address', function (): void {
    Http::fake();

    $result = kiyoh()->invite(makeInvitation(email: '   '));

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::NoEmail);

    Http::assertNothingSent();
});

it('reports a refused duplicate as a skip rather than a failure', function (array $body): void {
    Http::fake(['*' => Http::response($body, 400)]);

    $result = kiyoh()->invite(makeInvitation());

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::Duplicate)
        ->and($result->hasFailed())->toBeFalse();
})->with([
    'message wording' => [['message' => 'An invite has already been sent to this email address.']],
    'detailed error wording' => [['detailedError' => [['message' => 'Invite already requested within 30 days.']]]],
    'error code' => [['errorCode' => 'DUPLICATE_INVITE']],
]);

/*
 * The fallback is deliberate. Reporting a real outage as a duplicate would hide
 * it; reporting a duplicate as a failure only costs a log line.
 */
it('reports an error body it does not recognise as a failure, not a duplicate', function (): void {
    Http::fake(['*' => Http::response(['errorCode' => 'INTERNAL_ERROR', 'message' => 'Something went wrong.'], 400)]);

    $result = kiyoh()->invite(makeInvitation());

    expect($result->hasFailed())->toBeTrue()
        ->and($result->wasSkipped())->toBeFalse()
        ->and($result->skipReason)->toBeNull()
        ->and($result->status)->toBe(400)
        ->and($result->message)->toBe('errorCode INTERNAL_ERROR: Something went wrong.');
});

it('reports a server error as a failure carrying the status', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    $result = kiyoh()->invite(makeInvitation());

    expect($result->hasFailed())->toBeTrue()
        ->and($result->status)->toBe(500)
        ->and($result->message)->toBe('Kiyoh returned HTTP 500 without a readable error body.');
});

it('reports a connection failure without throwing', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('Connection timed out.'));

    $result = kiyoh()->invite(makeInvitation());

    expect($result->hasFailed())->toBeTrue()
        ->and($result->status)->toBeNull()
        ->and($result->message)->toBe('Could not reach Kiyoh: the connection failed or timed out.');
});

it('retries as often as configured and then gives up instead of throwing', function (): void {
    config()->set('reviews.http.attempts', 3);

    Http::fake(['*' => Http::response('', 500)]);

    $result = kiyoh()->invite(makeInvitation());

    expect($result->hasFailed())->toBeTrue()
        ->and($result->status)->toBe(500);

    Http::assertSentCount(3);
});

it('sends only once when a single attempt is configured', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    kiyoh()->invite(makeInvitation());

    Http::assertSentCount(1);
});

/*
 * The failure message is written to a log file verbatim, and Kiyoh is free to
 * quote the address it rejected back at us.
 */
it('strips a customer email address out of the message it hands back', function (): void {
    Http::fake(['*' => Http::response([
        'errorCode' => 'INVALID_EMAIL',
        'message' => 'The address klant@example.test was refused.',
        'detailedError' => [['message' => 'Contact klant@example.test to correct it.']],
    ], 422)]);

    $result = kiyoh()->invite(makeInvitation(email: 'klant@example.test'));

    expect($result->hasFailed())->toBeTrue()
        ->and($result->message)->toContain('[email redacted]')
        ->and($result->message)->not->toContain('klant@example.test')
        ->and($result->message)->not->toContain('@example.test')
        ->and($result->message)->toBe('errorCode INVALID_EMAIL: The address [email redacted] was refused.; Contact [email redacted] to correct it.');
});

it('trims an unreasonably long error body before handing it on', function (): void {
    Http::fake(['*' => Http::response(['message' => str_repeat('a', 900)], 400)]);

    $message = kiyoh()->invite(makeInvitation())->message;

    expect($message)->not->toBeNull()
        ->and(mb_strlen((string) $message))->toBe(500);
});

it('maps a locale onto the vocabulary Kiyoh accepts', function (?string $locale, string $expected): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    kiyoh()->invite(makeInvitation(locale: $locale));

    Http::assertSent(function (Request $request) use ($expected): bool {
        expect($request->data()['language'] ?? null)->toBe($expected);

        return true;
    });
})->with([
    'dutch stays dutch' => ['nl', 'nl'],
    'british english falls back to the bare language' => ['en_GB', 'en'],
    'spanish gains its region' => ['es', 'es-ES'],
    'portuguese means european portuguese' => ['pt', 'pt-PT'],
    'norwegian is aliased' => ['no', 'nn-NO'],
    'bokmal is aliased too' => ['nb', 'nn-NO'],
    'chinese gains its region' => ['zh', 'zh-CN'],
    'finnish gains its region' => ['fi', 'fi-FI'],
    'an unmappable locale uses the configured default' => ['xx', 'nl'],
    'no locale at all uses the configured default' => [null, 'nl'],
]);

it('skips when neither the invitation nor the configuration yields a language', function (): void {
    Http::fake();

    config()->set('reviews.providers.kiyoh.locale', 'klingon');

    $result = kiyoh()->invite(makeInvitation(locale: 'xx'));

    expect($result->wasSkipped())->toBeTrue()
        ->and($result->skipReason)->toBe(SkipReason::MissingData);

    Http::assertNothingSent();
});

it('pushes a delay that lands in the weekend forward to monday', function (string $today, int $delay, int $expected): void {
    Carbon::setTestNow($today.' 09:00:00');

    config()->set('reviews.providers.kiyoh.skip_weekends', true);

    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    kiyoh()->invite(makeInvitation(delayInDays: $delay));

    Http::assertSent(function (Request $request) use ($expected): bool {
        expect($request->data()['delay'] ?? null)->toBe($expected);

        return true;
    });
})->with([
    // 2026-08-12 is a Wednesday, so three days lands on Saturday.
    'saturday is pushed to monday' => ['2026-08-12', 3, 5],
    // 2026-08-13 is a Thursday, so three days lands on Sunday.
    'sunday is pushed to monday' => ['2026-08-13', 3, 4],
    // 2026-08-10 is a Monday, so three days lands on Thursday and is left alone.
    'a weekday is left alone' => ['2026-08-10', 3, 3],
]);

it('leaves the delay alone when weekend skipping is off', function (): void {
    Carbon::setTestNow('2026-08-12 09:00:00');

    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    kiyoh()->invite(makeInvitation(delayInDays: 3));

    Http::assertSent(function (Request $request): bool {
        expect($request->data()['delay'] ?? null)->toBe(3);

        return true;
    });
});

it('publishes the profile url as the review link and the badge', function (): void {
    expect(kiyoh()->reviewLink())->toBe('https://www.klantenvertellen.nl/profile/1234567')
        ->and(kiyoh()->reviewLink(makeInvitation()))->toBe('https://www.klantenvertellen.nl/profile/1234567');

    $badge = kiyoh()->badge();

    expect($badge)->not->toBeNull()
        ->and($badge?->render())->toContain('https://www.klantenvertellen.nl/profile/1234567')
        ->and($badge?->render())->toContain('data-location-id="1234567"');
});

it('renders no badge without a profile url', function (): void {
    config()->set('reviews.providers.kiyoh.profile_url', null);

    expect(kiyoh()->badge())->toBeNull()
        ->and(kiyoh()->reviewLink())->toBeNull();
});

afterEach(function (): void {
    Carbon::setTestNow();
});
