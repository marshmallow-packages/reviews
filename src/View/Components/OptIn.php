<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\View\Components;

use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Marshmallow\Reviews\Contracts\ReviewableOrder;
use Marshmallow\Reviews\Data\ReviewInvitation;
use Marshmallow\Reviews\Reviews;
use Override;

/**
 * <x-reviews::opt-in :order="$order" />
 *
 * Renders nothing at all when consent is withheld, when no active provider
 * renders an opt-in, or when the ones that do are unconfigured. The decision is
 * made on the finished markup rather than on the provider list, because a
 * provider that is active and configured may still decline for this one order,
 * and an order confirmation page should not carry an empty wrapper for it.
 */
final class OptIn extends Component
{
    private readonly ReviewInvitation $invitation;

    /**
     * Memoised so shouldRender() and render() do not each walk the providers.
     */
    private ?HtmlString $markup = null;

    public function __construct(
        /*
         * Private on purpose: Laravel merges a component's public properties
         * into the view data, and neither of these belongs there. The
         * invitation in particular carries an email address.
         */
        private readonly Reviews $reviews,
        ReviewableOrder|ReviewInvitation $order,
    ) {
        $this->invitation = $order instanceof ReviewInvitation
            ? $order
            : ReviewInvitation::fromOrder($order);
    }

    #[Override]
    public function shouldRender(): bool
    {
        return $this->markup()->toHtml() !== '';
    }

    /**
     * Component::resolveView() hands an Htmlable straight back to the view
     * factory, which calls toHtml() on it. The provider markup is therefore
     * emitted as it is, and never runs through Blade's escaping a second time.
     */
    public function render(): HtmlString
    {
        return $this->markup();
    }

    private function markup(): HtmlString
    {
        return $this->markup ??= $this->reviews->optInAll($this->invitation);
    }
}
