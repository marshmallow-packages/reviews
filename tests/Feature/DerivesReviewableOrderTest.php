<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Marshmallow\Reviews\Concerns\DerivesReviewableOrder;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Data\InvitationProduct;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Tests\Fixtures\PlainOrder;
use Marshmallow\Reviews\Tests\Fixtures\TestAddress;
use Marshmallow\Reviews\Tests\Fixtures\TestCountry;
use Marshmallow\Reviews\Tests\Fixtures\TestCustomer;
use Marshmallow\Reviews\Tests\Fixtures\TestOrder;
use Marshmallow\Reviews\Tests\Fixtures\TestOrderItem;
use Marshmallow\Reviews\Tests\Fixtures\TestProduct;

/**
 * The cart shaped order: a customer relation, a shipping address that is not an
 * Eloquent relation, and order lines that may or may not carry a product.
 *
 * Nothing is persisted. Every relation is set on the model, so the trait is
 * exercised without a schema, which is the point: it has to work against
 * whatever the site's models hand it.
 *
 * @param  list<TestOrderItem>  $items
 */
function cartOrder(array $attributes = [], ?TestAddress $address = null, array $items = []): TestOrder
{
    $order = new TestOrder(array_merge([
        'id' => 501,
        'shopping_cart_display_id' => 'ORD-2026-0001',
        'price_including_vat' => 4995,
    ], $attributes));

    $order->setRelation('customer', new TestCustomer([
        'email' => 'klant@example.test',
        'first_name' => 'Sanne',
        'last_name' => 'de Vries',
    ]));

    $order->setRelation('items', new Collection($items));

    return $order->withShippingAddress($address ?? utrecht());
}

function utrecht(): TestAddress
{
    $address = new TestAddress(['city' => 'Utrecht']);

    return $address->setRelation('country', new TestCountry(['alpha2' => 'NL']));
}

function orderLine(?TestProduct $product, int $quantity = 1): TestOrderItem
{
    $item = new TestOrderItem(['quantity' => $quantity]);

    return $item->setRelation('product', $product);
}

function cushion(int $id = 77, ?string $gtin = '0123456789012'): TestProduct
{
    return new TestProduct(['id' => $id, 'ni' => $gtin, 'name' => 'Kussen']);
}

it('derives every field a provider needs from a cart shaped order', function (): void {
    app()->setLocale('nl');

    $order = cartOrder(items: [orderLine(cushion())]);

    expect($order->reviewerEmail())->toBe('klant@example.test')
        ->and($order->reviewerFirstName())->toBe('Sanne')
        ->and($order->reviewerLastName())->toBe('de Vries')
        ->and($order->reviewOrderReference())->toBe('ORD-2026-0001')
        ->and($order->reviewLocale())->toBe('nl')
        ->and($order->reviewCity())->toBe('Utrecht')
        ->and($order->reviewCountryCode())->toBe('NL')
        ->and($order->reviewOrderTotalInCents())->toBe(4995)
        ->and($order->reviewProducts())->toHaveCount(1)
        ->and($order->reviewProducts()[0]->identifier)->toBe('77')
        ->and($order->reviewProducts()[0]->gtin)->toBe('0123456789012')
        ->and($order->reviewProducts()[0]->name)->toBe('Kussen')
        ->and($order->reviewProducts()[0]->quantity)->toBe(1);
});

/*
 * The trap that matters. marshmallow/cart's Order::shippingAddress() is a plain
 * method returning a Model, not an Eloquent relation, so reading the property
 * makes Eloquent throw. The trait has to survive that and still derive the city
 * and the country, or every cart site fatals on its order confirmation page.
 */
it('derives the address through a plain method that eloquent refuses as a relation', function (): void {
    $order = cartOrder();

    expect(fn (): mixed => $order->shippingAddress)->toThrow(LogicException::class);

    expect($order->reviewCity())->toBe('Utrecht')
        ->and($order->reviewCountryCode())->toBe('NL');
});

/*
 * The same trap on a model shaped exactly like the cart's: the method exists,
 * the property does not, so the read goes through Eloquent's __isset and throws
 * before the trait can fall back to calling the method.
 */
it('falls back to calling the method when there is no property to read at all', function (): void {
    $order = new class extends Model implements ReviewableOrder
    {
        use DerivesReviewableOrder;

        protected $guarded = [];

        public function shippingAddress(): TestAddress
        {
            return utrecht();
        }
    };

    expect(fn (): mixed => $order->shippingAddress)->toThrow(LogicException::class);

    expect($order->reviewCity())->toBe('Utrecht')
        ->and($order->reviewCountryCode())->toBe('NL');
});

it('leaves out an order line that carries no product', function (): void {
    $order = cartOrder(items: [
        orderLine(cushion()),
        // The shipping line marshmallow/cart stores alongside the real items.
        orderLine(null),
    ]);

    expect($order->reviewProducts())->toHaveCount(1)
        ->and($order->reviewProducts()[0]->identifier)->toBe('77');
});

it('leaves out an order line that was zeroed out', function (): void {
    $order = cartOrder(items: [
        orderLine(cushion(77), 2),
        orderLine(cushion(78), 0),
    ]);

    expect(array_map(
        static fn (InvitationProduct $product): string => $product->identifier,
        $order->reviewProducts(),
    ))->toBe(['77']);
});

it('has no products at all when the order has no lines', function (): void {
    expect(cartOrder()->reviewProducts())->toBe([]);
});

it('falls back to the primary key when the reference column is empty', function (?string $reference): void {
    expect(cartOrder(['shopping_cart_display_id' => $reference])->reviewOrderReference())->toBe('501');
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);

it('reads renamed columns and relations out of config', function (): void {
    config()->set([
        'reviews.order.email_column' => 'email_address',
        'reviews.order.first_name_column' => 'given_name',
        'reviews.order.city_column' => 'town',
        'reviews.order.country_code_column' => 'iso',
        'reviews.order.reference_column' => 'order_number',
        'reviews.order.total_column' => 'grand_total',
        'reviews.order.gtin_column' => 'ean',
        'reviews.order.quantity_column' => 'amount',
    ]);

    $address = new TestAddress(['town' => 'Amersfoort']);
    $address->setRelation('country', new TestCountry(['iso' => 'BE']));

    $product = new TestProduct(['id' => 9, 'ean' => '4006381333931', 'name' => 'Deken']);

    $order = new TestOrder(['id' => 1, 'order_number' => 'REN-1', 'grand_total' => 1250]);
    $order->setRelation('customer', new TestCustomer([
        'email_address' => 'renamed@example.test',
        'given_name' => 'Joris',
    ]));
    $order->setRelation('items', new Collection([
        (new TestOrderItem(['amount' => 3]))->setRelation('product', $product),
    ]));
    $order->withShippingAddress($address);

    expect($order->reviewerEmail())->toBe('renamed@example.test')
        ->and($order->reviewerFirstName())->toBe('Joris')
        ->and($order->reviewCity())->toBe('Amersfoort')
        ->and($order->reviewCountryCode())->toBe('BE')
        ->and($order->reviewOrderReference())->toBe('REN-1')
        ->and($order->reviewOrderTotalInCents())->toBe(1250)
        ->and($order->reviewProducts()[0]->gtin)->toBe('4006381333931')
        ->and($order->reviewProducts()[0]->quantity)->toBe(3);
});

it('degrades to null when the order has no customer and no address', function (): void {
    $order = new TestOrder(['id' => 7]);

    expect($order->reviewerEmail())->toBeNull()
        ->and($order->reviewerFirstName())->toBeNull()
        ->and($order->reviewerLastName())->toBeNull()
        ->and($order->reviewCity())->toBeNull()
        ->and($order->reviewCountryCode())->toBeNull()
        ->and($order->reviewOrderTotalInCents())->toBeNull()
        ->and($order->reviewProducts())->toBe([])
        ->and($order->reviewOrderReference())->toBe('7');
});

it('uses the estimated delivery date resolver from config', function (): void {
    config()->set(
        'reviews.estimated_delivery_date',
        static fn (ReviewableOrder $order): CarbonImmutable => CarbonImmutable::parse('2026-09-01'),
    );

    expect(cartOrder()->reviewEstimatedDeliveryDate()?->format('Y-m-d'))->toBe('2026-09-01');
});

it('accepts a date string from the resolver', function (): void {
    config()->set('reviews.estimated_delivery_date', static fn (ReviewableOrder $order): string => '2026-09-02');

    expect(cartOrder()->reviewEstimatedDeliveryDate()?->format('Y-m-d'))->toBe('2026-09-02');
});

it('has no estimated delivery date without a resolver', function (): void {
    config()->set('reviews.estimated_delivery_date', null);

    expect(cartOrder()->reviewEstimatedDeliveryDate())->toBeNull();
});

it('yields null rather than blowing up when the resolver throws', function (): void {
    config()->set('reviews.estimated_delivery_date', static function (ReviewableOrder $order): CarbonImmutable {
        throw new RuntimeException('The delivery service is down.');
    });

    expect(cartOrder()->reviewEstimatedDeliveryDate())->toBeNull();
});

it('builds an invitation from an order that is not eloquent and not on our cart', function (): void {
    $order = new PlainOrder(
        reference: 'PLAIN-1',
        deliveryDate: CarbonImmutable::parse('2026-09-03'),
        products: [new InvitationProduct(identifier: '1', gtin: '0123456789012')],
    );

    $invitation = ReviewInvitation::fromOrder($order);

    expect($invitation)->toBeInstanceOf(ReviewInvitation::class)
        ->and($invitation->email)->toBe('klant@example.test')
        ->and($invitation->orderReference)->toBe('PLAIN-1')
        ->and($invitation->fullName())->toBe('Sanne de Vries')
        ->and($invitation->countryCode)->toBe('NL')
        ->and($invitation->city)->toBe('Utrecht')
        ->and($invitation->estimatedDeliveryDate?->format('Y-m-d'))->toBe('2026-09-03')
        ->and($invitation->orderTotalInCents)->toBe(4995)
        ->and($invitation->gtins())->toBe(['0123456789012']);
});

it('builds an invitation straight off the cart shaped order', function (): void {
    $invitation = ReviewInvitation::fromOrder(cartOrder(items: [orderLine(cushion())]));

    expect($invitation->email)->toBe('klant@example.test')
        ->and($invitation->orderReference)->toBe('ORD-2026-0001')
        ->and($invitation->city)->toBe('Utrecht')
        ->and($invitation->countryCode)->toBe('NL')
        ->and($invitation->gtins())->toBe(['0123456789012']);
});
