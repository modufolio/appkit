<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\DependencyInjection;

interface ControllerArgumentResolverInterface
{
    /**
     * @param string $controllerClass The controller class name
     * @return array List of raw dependency descriptors (strings like %param%, class names, etc.)
     */
    public function resolveArguments(string $controllerClass): array;
}
