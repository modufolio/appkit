<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\DependencyInjection;

final class ReflectionControllerArgumentResolver implements ControllerArgumentResolverInterface
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function resolveArguments(string $controllerClass): array
    {
        $refClass = new \ReflectionClass($controllerClass);
        $constructor = $refClass->getConstructor();
        $deps = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                if ($param->isDefaultValueAvailable()) {
                    // Always prefer defaults
                    $deps[] = $param->getDefaultValue();
                } elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    // Class type → service
                    $deps[] = $type->getName();
                } elseif ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                    $paramName = $param->getName();
                    if ($type->allowsNull()) {
                        // ?string → %param% if available, else null
                        $deps[] = $this->container->hasParameter($paramName)
                            ? '%' . $paramName . '%'
                            : null;
                    } else {
                        // strict string → required parameter
                        $deps[] = '%' . $paramName . '%';
                    }
                } else {
                    $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
                    throw new \RuntimeException(
                        "Cannot resolve parameter \${$param->getName()} of type '{$typeName}' " .
                        "for controller '$controllerClass'."
                    );
                }
            }
        }

        return $deps;
    }
}
