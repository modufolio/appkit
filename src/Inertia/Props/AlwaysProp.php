<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop that travels on every response, a partial reload's `only` and
 * `except` notwithstanding: the current user, validation errors.
 */
final class AlwaysProp extends Prop
{
    public static function of(mixed $value): self
    {
        return new self($value);
    }
}
