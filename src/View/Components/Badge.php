<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\View\Components;

use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use Marshmallow\Reviews\Reviews;
use Override;

/**
 * <x-reviews::badge />
 *
 * Sits anywhere on the site and is tied to no order. Renders nothing at all
 * when consent is withheld or when no active provider publishes a badge, so a
 * layout can carry the tag before a provider is configured.
 */
final class Badge extends Component
{
    /**
     * Memoised so shouldRender() and render() do not each walk the providers.
     */
    private ?HtmlString $markup = null;

    public function __construct(
        private readonly Reviews $reviews,
    ) {}

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
        return $this->markup ??= $this->reviews->badgeAll();
    }
}
