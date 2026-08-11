<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Listeners\SendInvitationForOrderEvent;
use Marshmallow\Reviews\ReviewsServiceProvider;
use Marshmallow\Reviews\Tests\Fixtures\PlainOrder;

final class DummyOrderCompleted
{
    public function __construct(public readonly ReviewableOrder $order) {}
}

/**
 * The marshmallow/payable shape: an event that carries something other than an
 * order, which only a resolve_order callable can turn into one.
 */
function paymentEvent(?ReviewableOrder $order = null): object
{
    return new class($order)
    {
        public function __construct(public readonly ?ReviewableOrder $payment) {}
    };
}

/**
 * The easy shape: an event with a public $order the fallback can read.
 */
function orderEvent(ReviewableOrder $order): object
{
    return new class($order)
    {
        public function __construct(public readonly ReviewableOrder $order) {}
    };
}

function listener(): SendInvitationForOrderEvent
{
    return app(SendInvitationForOrderEvent::class);
}

beforeEach(function (): void {
    Queue::fake();
});

it('dispatches nothing while event listening is switched off', function (): void {
    config()->set('reviews.events.enabled', false);

    listener()->handle(orderEvent(new PlainOrder));

    Queue::assertNothingPushed();
});

it('dispatches with the order the resolver hands back', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => static fn (object $event): ?ReviewableOrder => $event->payment ?? null,
    ]);

    listener()->handle(paymentEvent(new PlainOrder(reference: 'RESOLVED-1')));

    Queue::assertPushed(
        SendReviewInvitation::class,
        static fn (SendReviewInvitation $job): bool => $job->invitation->orderReference === 'RESOLVED-1',
    );
});

it('reads a public order property when no resolver is configured', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => null,
    ]);

    listener()->handle(orderEvent(new PlainOrder(reference: 'FALLBACK-1')));

    Queue::assertPushed(
        SendReviewInvitation::class,
        static fn (SendReviewInvitation $job): bool => $job->invitation->orderReference === 'FALLBACK-1',
    );
});

it('dispatches nothing for an event it cannot resolve an order from', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => null,
    ]);

    listener()->handle(paymentEvent());

    Queue::assertNothingPushed();
});

it('dispatches nothing when the resolver returns something that is not an order', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => static fn (object $event): string => 'not an order',
    ]);

    listener()->handle(paymentEvent(new PlainOrder));

    Queue::assertNothingPushed();
});

it('dispatches nothing for an order without an email address', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => null,
    ]);

    listener()->handle(orderEvent(new PlainOrder(email: null)));

    Queue::assertNothingPushed();
});

/*
 * A review invitation is never worth breaking the payment it hangs off.
 */
it('swallows a resolver that throws instead of breaking the event', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.resolve_order' => static fn (object $event): ReviewableOrder => throw new RuntimeException('boom'),
    ]);

    listener()->handle(paymentEvent(new PlainOrder));

    Queue::assertNothingPushed();
});

it('binds the listener to the configured events when they are switched on', function (): void {
    config()->set([
        'reviews.events.enabled' => true,
        'reviews.events.listen' => [DummyOrderCompleted::class],
        'reviews.events.resolve_order' => null,
    ]);

    // Re-registering is what the service provider does at boot: this proves the
    // wiring rather than only the listener class.
    app()->register(ReviewsServiceProvider::class, true);

    event(new DummyOrderCompleted(new PlainOrder(reference: 'EVENT-1')));

    Queue::assertPushed(
        SendReviewInvitation::class,
        static fn (SendReviewInvitation $job): bool => $job->invitation->orderReference === 'EVENT-1',
    );
});
