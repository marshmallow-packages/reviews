<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Contracts;

/**
 * The base every review provider shares. It says who the provider is and
 * whether it can do anything at all, and nothing else.
 *
 * What a provider can actually do is expressed by the capability interfaces
 * that extend this one: SendsInvitations, RendersOptIn, RendersBadge,
 * ImportsReviews, RespondsToReviews and ProvidesReviewLink. A provider
 * implements exactly the ones it can honour, so "can this send an invitation?"
 * is a type question rather than a null check on a method that pretends to
 * exist.
 */
interface ReviewProvider
{
    /**
     * The key this provider is resolved by, matching the config driver name.
     */
    public function name(): string;

    /**
     * Whether everything needed to do the job is present: API tokens, a
     * merchant id, a location id. An unconfigured provider is skipped rather
     * than failing, so a site without credentials is not punished for it.
     */
    public function isConfigured(): bool;
}
