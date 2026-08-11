<?php

declare(strict_types=1);

namespace Marshmallow\Reviews\Support;

/**
 * Coercion for values that came out of config, which in practice means values
 * that came out of env().
 *
 * Everything arrives as a string there, so a location id set to 12345 is as
 * likely to be an int as a string and REVIEWS_ENABLED=false is the four
 * character string "false". Each caller used to carry its own copy of these
 * three checks, which is how one of them ends up drifting from the others.
 */
final class ConfigValue
{
    /**
     * FILTER_NULL_ON_FAILURE rather than a cast, so an unparseable value is
     * false instead of true. Pass the config default in through
     * Repository::get(), so an absent key is coerced the same way a present one
     * is.
     */
    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Null for anything that is not a usable string, so a caller can tell an
     * unset credential apart from an empty one without repeating the check.
     */
    public static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function int(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
