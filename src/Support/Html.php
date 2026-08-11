<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Stringable;

/**
 * Raw markup a provider wants to emit without going through a view.
 *
 * The capability interfaces return Renderable, because a Blade view is what a
 * bundled provider returns and Illuminate\Contracts\View\View extends
 * Renderable. Laravel's own HtmlString is not usable there: it implements
 * Htmlable and has toHtml(), not render(). Rather than widening the contract
 * to Renderable|Htmlable and making every consumer handle two shapes, a
 * provider with a string in hand wraps it in this.
 */
final readonly class Html implements Htmlable, Renderable, Stringable
{
    public function __construct(private string $html) {}

    public function render(): string
    {
        return $this->html;
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
