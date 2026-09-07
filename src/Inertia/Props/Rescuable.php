<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Inertia\Props;

/** A prop whose failure to resolve yields null and is listed under `rescuedProps`, not an error page. */
interface Rescuable
{
    public function shouldRescue(): bool;
}
