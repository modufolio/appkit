<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop the client keeps across visits: sent on the first response, listed
 * under `onceProps`, and left out afterwards while the client says it still
 * holds it. `as()` names it (the default is its path), `until()` expires it,
 * `fresh()` forces a resend.
 */
final class OnceProp extends Prop implements Onceable
{
    use ResolvesOnce;

    private function __construct(\Closure $value)
    {
        parent::__construct($value);
        $this->once = true;
    }

    public static function of(\Closure $value): self
    {
        return new self($value);
    }
}
