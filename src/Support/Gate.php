<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The two questions every provider has to ask before it does anything:
 * may I send, and may I render.
 *
 * This is its own object rather than methods on the manager because the
 * providers that need it must not depend on the manager that resolves them,
 * and because both checks have to hold at both levels. Gating only the fan-out
 * would leave Reviews::driver('kiyoh')->invite() posting to a live API with
 * the package switched off, and Reviews::driver('google')->badge() loading
 * Google's script with no consent at all. Those are exactly the calls a site
 * makes when it wants one specific provider in one specific place.
 */
final readonly class Gate
{
    public function __construct(private Repository $config) {}

    /**
     * The master switch. Off means the package does nothing at all, which is
     * what makes it safe to install on staging where inviting a real customer
     * to review a test order is a genuine hazard.
     */
    public function enabled(): bool
    {
        return ConfigValue::bool($this->config->get('reviews.enabled', true));
    }

    /**
     * Sending is deliberately not gated by consent. Posting an invitation to a
     * provider's API from our server is a controller to processor transfer
     * under the data processing agreement, not a cookie placement.
     */
    public function maySend(): bool
    {
        return $this->enabled();
    }

    /**
     * Rendering is, because a client side module loads a third party script and
     * sets its cookies in the visitor's own browser.
     */
    public function mayRender(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $consent = $this->config->get('reviews.consent');

        // No callback configured means the site has not wired a cookie banner
        // to us. Defaulting to true keeps a package install from silently
        // breaking a badge, and reviews:doctor warns when a provider that
        // renders client side is active without one.
        if (! is_callable($consent)) {
            return true;
        }

        return $consent() === true;
    }
}
