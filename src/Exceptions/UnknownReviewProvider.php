<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Exceptions;

use InvalidArgumentException;
use Marshmallow\Reviews\Contracts\ReviewProvider;

/**
 * Illuminate\Support\Manager throws a bare "Driver [x] not supported." which
 * does not say what is supported. This replaces it with a message that names
 * the registered providers, so a typo in config is a five second fix.
 */
final class UnknownReviewProvider extends InvalidArgumentException
{
    /**
     * @param  list<string>  $available
     */
    public static function named(string $name, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'Unknown review provider [%s]. Available: %s. Register a custom one with Reviews::extend().',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    public static function isNotAProvider(string $name): self
    {
        return new self(sprintf(
            'The review provider [%s] must implement %s.',
            $name,
            ReviewProvider::class,
        ));
    }
}
