<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Providers\GoogleProvider;

function google(): GoogleProvider
{
    $provider = reviewManager()->driver('google');

    expect($provider)->toBeInstanceOf(GoogleProvider::class);

    /** @var GoogleProvider $provider */
    return $provider;
}

/**
 * The value Google's snippet was handed for one key, decoded back out of the
 * JavaScript. Asserting on the decoded value rather than on the raw markup is
 * what makes an escaping test meaningful.
 */
function optInLiteral(string $html, string $key): string
{
    $found = preg_match('/\b'.preg_quote($key, '/').': (.*),$/m', $html, $matches);

    expect($found)->toBe(1, "The opt-in snippet carries no [{$key}] key.");

    return trim($matches[1]);
}

function optInValue(string $html, string $key): mixed
{
    return json_decode(optInLiteral($html, $key), true, 512, JSON_THROW_ON_ERROR);
}

function completeInvitation(string $orderReference = 'ORDER-42'): ReviewInvitation
{
    return makeInvitation(
        orderReference: $orderReference,
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
    );
}

beforeEach(function (): void {
    configureGoogle();
});

it('renders the opt-in with every field Google requires', function (): void {
    $view = google()->optIn(completeInvitation());

    expect($view)->not->toBeNull();

    $html = (string) $view?->render();

    expect($html)->toContain('https://apis.google.com/js/platform.js?onload=renderOptIn')
        ->and($html)->toContain('hl=nl')
        ->and($html)->toContain('window.gapi.surveyoptin.render')
        ->and(optInValue($html, 'merchant_id'))->toBe(11223344)
        ->and(optInValue($html, 'order_id'))->toBe('ORDER-42')
        ->and(optInValue($html, 'email'))->toBe('klant@example.test')
        ->and(optInValue($html, 'delivery_country'))->toBe('NL')
        ->and(optInValue($html, 'estimated_delivery_date'))->toBe('2026-08-20')
        ->and(optInValue($html, 'opt_in_style'))->toBe('CENTER_DIALOG');
});

it('keeps a non numeric merchant id a string', function (): void {
    config()->set('reviews.providers.google.merchant_id', 'MC-42');

    $html = (string) google()->optIn(completeInvitation())?->render();

    expect(optInValue($html, 'merchant_id'))->toBe('MC-42');
});

it('renders nothing without a merchant id', function (): void {
    config()->set('reviews.providers.google.merchant_id', null);

    expect(google()->optIn(completeInvitation()))->toBeNull()
        ->and(google()->badge())->toBeNull()
        ->and(google()->isConfigured())->toBeFalse();
});

it('declines rather than emitting a half filled module', function (ReviewInvitation $invitation): void {
    expect(google()->optIn($invitation))->toBeNull();
})->with([
    'no email' => [fn (): ReviewInvitation => makeInvitation(
        email: '',
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
    )],
    'blank email' => [fn (): ReviewInvitation => makeInvitation(
        email: '   ',
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
    )],
    'no country code' => [fn (): ReviewInvitation => makeInvitation(
        countryCode: null,
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
    )],
    'no estimated delivery date' => [fn (): ReviewInvitation => makeInvitation()],
]);

it('lists the gtins only when the invitation carries some', function (): void {
    $without = (string) google()->optIn(completeInvitation())?->render();

    expect($without)->not->toContain('products:');

    $with = (string) google()->optIn(makeInvitation(
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
        products: [
            new InvitationProduct(identifier: '1', gtin: '0123456789012'),
            new InvitationProduct(identifier: '2', gtin: null),
            new InvitationProduct(identifier: '3', gtin: '9876543210987'),
        ],
    ))?->render();

    expect(optInValue($with, 'products'))->toBe([
        ['gtin' => '0123456789012'],
        ['gtin' => '9876543210987'],
    ]);
});

it('renders the badge with the merchant id and the configured position', function (): void {
    $html = (string) google()->badge()?->render();

    expect($html)->toContain('data-merchant-id="11223344"')
        ->and($html)->toContain('data-position="BOTTOM_RIGHT"')
        ->and($html)->toContain('class="g-ratingbadge"')
        ->and($html)->toContain('https://apis.google.com/js/platform.js?hl=nl');
});

it('follows the app locale when no module language is configured', function (): void {
    config()->set([
        'reviews.providers.google.language' => null,
        'app.locale' => 'de',
    ]);

    expect((string) google()->badge()?->render())->toContain('hl=de');
});

/*
 * The whole snippet is a JavaScript object literal, so a stray quote or
 * backslash in an order reference is a way out of the string it sits in.
 */
it('encodes an order reference that would otherwise break out of the javascript', function (): void {
    $reference = 'O"R\\D</script><script>alert(1)</script>';

    $html = (string) google()->optIn(completeInvitation($reference))?->render();
    $literal = optInLiteral($html, 'order_id');

    expect(json_decode($literal, true, 512, JSON_THROW_ON_ERROR))->toBe($reference)
        ->and($html)->not->toContain('O"R')
        ->and($html)->not->toContain('</script><script>alert(1)')
        // The literal is a single JavaScript string: the only quotes left in it
        // are the two delimiters, and no angle bracket survives unescaped.
        ->and(substr_count($literal, '"'))->toBe(2)
        ->and($literal)->toStartWith('"')
        ->and($literal)->toEndWith('"')
        ->and($literal)->not->toContain('<')
        ->and($literal)->not->toContain('>');
});

it('matches the rendered opt-in snapshot', function (): void {
    expect((string) google()->optIn(makeInvitation(
        orderReference: 'ORDER-42',
        estimatedDeliveryDate: CarbonImmutable::parse('2026-08-20'),
        products: [new InvitationProduct(identifier: '1', gtin: '0123456789012')],
    ))?->render())->toMatchSnapshot();
});

it('matches the rendered badge snapshot', function (): void {
    expect((string) google()->badge()?->render())->toMatchSnapshot();
});
