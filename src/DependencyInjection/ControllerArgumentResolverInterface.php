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
     * @return list<mixed> List of raw dependency descriptors (strings like %param%, class names, etc.)
     */
    public function resolveArguments(string $controllerClass): array;
}
