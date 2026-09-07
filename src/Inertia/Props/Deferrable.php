<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/** A prop left out of the first response and fetched by the client afterwards, by group. */
interface Deferrable
{
    public function shouldDefer(): bool;

    public function group(): string;
}
