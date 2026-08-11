<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Tests\Fixtures\PlainOrder;

it('maps every field off the order', function (): void {
    $invitation = ReviewInvitation::fromOrder(new PlainOrder(
        email: 'klant@example.test',
        reference: 'ORDER-5',
        firstName: 'Sanne',
        lastName: 'de Vries',
        locale: 'nl',
        countryCode: 'NL',
        city: 'Utrecht',
        deliveryDate: CarbonImmutable::parse('2026-08-20'),
        total: 4995,
        products: [new InvitationProduct(identifier: '1', gtin: '0123456789012')],
    ));

    expect($invitation->email)->toBe('klant@example.test')
        ->and($invitation->orderReference)->toBe('ORDER-5')
        ->and($invitation->firstName)->toBe('Sanne')
        ->and($invitation->lastName)->toBe('de Vries')
        ->and($invitation->locale)->toBe('nl')
        ->and($invitation->countryCode)->toBe('NL')
        ->and($invitation->city)->toBe('Utrecht')
        ->and($invitation->estimatedDeliveryDate?->format('Y-m-d'))->toBe('2026-08-20')
        ->and($invitation->orderTotalInCents)->toBe(4995)
        ->and($invitation->products)->toHaveCount(1)
        ->and($invitation->delayInDays)->toBeNull()
        ->and($invitation->metadata)->toBe([]);
});

it('turns a missing email address into an empty one rather than failing', function (): void {
    $invitation = ReviewInvitation::fromOrder(new PlainOrder(email: null));

    expect($invitation->email)->toBe('')
        ->and($invitation->hasEmail())->toBeFalse();
});

it('knows whether it has a usable email address', function (string $email, bool $expected): void {
    expect(makeInvitation(email: $email)->hasEmail())->toBe($expected);
})->with([
    'an address' => ['klant@example.test', true],
    'empty' => ['', false],
    'whitespace only' => ["  \t ", false],
]);

it('joins the name it has', function (?string $first, ?string $last, ?string $expected): void {
    expect(makeInvitation(firstName: $first, lastName: $last)->fullName())->toBe($expected);
})->with([
    'both' => ['Sanne', 'de Vries', 'Sanne de Vries'],
    'first only' => ['Sanne', null, 'Sanne'],
    'last only' => [null, 'de Vries', 'de Vries'],
    'neither' => [null, null, null],
]);

it('lists only the gtins it actually has', function (): void {
    $invitation = makeInvitation(products: [
        new InvitationProduct(identifier: '1', gtin: '0123456789012'),
        new InvitationProduct(identifier: '2', gtin: null),
        new InvitationProduct(identifier: '3', gtin: ''),
        new InvitationProduct(identifier: '4', gtin: '9876543210987'),
    ]);

    expect($invitation->gtins())->toBe(['0123456789012', '9876543210987']);
});

/*
 * Everything this package logs goes through context(). If the customer can be
 * identified from what comes out of here, the "never log personal data" promise
 * is not enforceable anywhere else either.
 */
it('leaves the customer out of the context it offers for logging', function (): void {
    $context = makeInvitation(
        email: 'klant@example.test',
        orderReference: 'ORDER-5',
        firstName: 'Sanne',
        lastName: 'de Vries',
        city: 'Utrecht',
        products: [new InvitationProduct(identifier: '1')],
    )->context();

    expect($context)->toBe([
        'order_reference' => 'ORDER-5',
        'locale' => 'nl',
        'country_code' => 'NL',
        'products' => 1,
    ]);

    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('klant@example.test')
        ->not->toContain('Sanne')
        ->not->toContain('de Vries')
        ->not->toContain('Utrecht');
});
