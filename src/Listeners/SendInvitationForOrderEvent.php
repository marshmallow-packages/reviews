<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Listeners;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Jobs\SendReviewInvitation;
use Marshmallow\Reviews\Support\ConfigValue;
use Throwable;

/**
 * Turns whatever event a site considers "this order is done" into a queued
 * invitation.
 *
 * The event type is not known here, and cannot be. marshmallow/cart has no
 * completed order event at all: OrderCreated fires before payment, so binding
 * to it would invite people who abandoned checkout. The closest proxy,
 * PaymentStatusPaid from marshmallow/payable, carries a Payment rather than an
 * Order. Mapping event to order is therefore per site, which is what the
 * resolve_order callable in config is for. The public $order fallback only
 * covers the events that already hand one over.
 */
final class SendInvitationForOrderEvent
{
    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $bus,
    ) {}

    public function handle(object $event): void
    {
        try {
            $this->invite($event);
        } catch (Throwable) {
            // An invitation is never worth breaking the event that triggered
            // it: the payment it hangs off has already been processed.
        }
    }

    private function invite(object $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $order = $this->resolveOrder($event);

        if (! $order instanceof ReviewableOrder) {
            return;
        }

        $invitation = ReviewInvitation::fromOrder($order);

        if (! $invitation->hasEmail()) {
            return;
        }

        $this->bus->dispatch(new SendReviewInvitation($invitation));
    }

    private function resolveOrder(object $event): ?ReviewableOrder
    {
        $resolver = $this->config->get('reviews.events.resolve_order');

        if (is_callable($resolver)) {
            $resolved = $resolver($event);

            return $resolved instanceof ReviewableOrder ? $resolved : null;
        }

        // get_object_vars from outside the class sees public properties only,
        // which is exactly the contract documented for this fallback.
        $order = get_object_vars($event)['order'] ?? null;

        return $order instanceof ReviewableOrder ? $order : null;
    }

    private function enabled(): bool
    {
        return ConfigValue::bool($this->config->get('reviews.events.enabled', false));
    }
}
