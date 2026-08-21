<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ControllerArgumentResolverInterface
{
    /**
     * @param class-string $controllerClass The controller class name
     *
     * @return array<array-key, mixed> Raw dependency descriptors (strings like
     *                                 %param%, class names, etc.). String keys
     *                                 are constructor parameter names and are
     *                                 spread as named arguments; a list is
     *                                 spread positionally.
     */
    public function resolveArguments(string $controllerClass): array;
}
