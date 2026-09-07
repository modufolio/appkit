<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop sent only when a partial reload names it in `only`: never on a
 * full page load, never on a partial reload that does not ask. Inertia's
 * `optional()`, earlier `lazy()`. May be `once()`.
 */
final class OptionalProp extends Prop implements IgnoreFirstLoad, Onceable
{
    use ResolvesOnce;

    public static function of(\Closure $value): self
    {
        return new self($value);
    }
}
