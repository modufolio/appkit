<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

/**
 * Read-only access to container parameters.
 *
 * Kept separate from the full application contract so components that only
 * need to look parameters up can depend on the narrow interface.
 */
interface ParameterAccessorInterface
{
    public function hasParameter(string $name): bool;
}
