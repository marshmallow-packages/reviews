<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Marshmallow\Reviews\Contracts\RendersBadge;
use Marshmallow\Reviews\Contracts\RendersOptIn;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Support\ConfigValue;
use Marshmallow\Reviews\Support\Gate;

/**
 * Google Customer Reviews has no server side invitation API at all. Google
 * collects the opt-in in the visitor's own browser, from a snippet on the order
 * confirmation page, and sends the survey itself. That is why this provider
 * deliberately does not implement SendsInvitations: there is nothing to call.
 *
 * The consequence is that the customer's email address is transferred to Google
 * from the browser rather than from our server, which is what puts this provider
 * behind the consent callback in Reviews::optInAll().
 */
final class GoogleProvider implements RendersBadge, RendersOptIn
{
    public function __construct(
        private readonly Repository $config,
        private readonly ViewFactory $views,
        private readonly Gate $gate,
    ) {}

    public function name(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return $this->merchantId() !== null;
    }

    /**
     * Google requires the merchant id, the email address, the delivery country
     * and the estimated delivery date. A snippet missing one of them fails
     * silently in the visitor's browser, which is worse than rendering nothing,
     * so this declines rather than emitting a half filled module.
     */
    public function optIn(ReviewInvitation $invitation): ?View
    {
        // Checked here as well as in the fan-out, because this module loads
        // Google's script, sets Google's cookies and hands Google the
        // customer's email address from the visitor's own browser. A direct
        // call to this provider must not be a way around the cookie banner.
        if (! $this->gate->mayRender()) {
            return null;
        }

        $merchantId = $this->merchantId();

        if ($merchantId === null) {
            return null;
        }

        if (! $invitation->hasEmail()) {
            return null;
        }

        if ($invitation->countryCode === null || $invitation->estimatedDeliveryDate === null) {
            return null;
        }

        return $this->views->make('reviews::google.opt-in', [
            'merchant_id' => $merchantId,
            'order_id' => $invitation->orderReference,
            'email' => $invitation->email,
            'delivery_country' => $invitation->countryCode,
            'estimated_delivery_date' => $invitation->estimatedDeliveryDate->format('Y-m-d'),
            'opt_in_style' => $this->stringConfig('reviews.providers.google.opt_in_style') ?? 'CENTER_DIALOG',
            'language' => $this->language(),
            'products' => $invitation->gtins(),
        ]);
    }

    public function badge(): ?View
    {
        if (! $this->gate->mayRender()) {
            return null;
        }

        $merchantId = $this->merchantId();

        if ($merchantId === null) {
            return null;
        }

        return $this->views->make('reviews::google.badge', [
            'merchant_id' => $merchantId,
            'badge_position' => $this->stringConfig('reviews.providers.google.badge_position') ?? 'BOTTOM_RIGHT',
            'language' => $this->language(),
        ]);
    }

    /**
     * A merchant id is a number, but it arrives from env() as a string. It is
     * handed on as an int when it is numeric, because that is the form Google's
     * own documented snippet uses.
     */
    private function merchantId(): int|string|null
    {
        $merchantId = $this->stringConfig('reviews.providers.google.merchant_id');

        if ($merchantId === null) {
            return null;
        }

        return ctype_digit($merchantId) ? (int) $merchantId : $merchantId;
    }

    /**
     * The language of the module itself. Null means Google follows its own
     * default, which the app locale is a better guess at than nothing.
     */
    private function language(): ?string
    {
        $language = $this->stringConfig('reviews.providers.google.language');

        if ($language !== null) {
            return $language;
        }

        return $this->stringConfig('app.locale');
    }

    private function stringConfig(string $key): ?string
    {
        return ConfigValue::string($this->config->get($key));
    }
}
