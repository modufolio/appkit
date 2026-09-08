<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/**
 * A prop with a rule about when it travels. Invokable: calling it yields the
 * value, computing a closure only then — so a prop the request leaves out
 * never pays for itself.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class Prop
{
    protected function __construct(protected readonly mixed $value)
    {
    }

    public function __invoke(): mixed
    {
        return $this->value instanceof \Closure ? ($this->value)() : $this->value;
    }
}
