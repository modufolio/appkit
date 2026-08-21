<?php

declare(strict_types=1);

namespace Modufolio\Appkit\DependencyInjection;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ReflectionControllerArgumentResolver implements ControllerArgumentResolverInterface
{
    private ParameterAccessorInterface $container;

    public function __construct(ParameterAccessorInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @param class-string $controllerClass
     *
     * @return array<string, mixed> keyed by constructor parameter name
     */
    public function resolveArguments(string $controllerClass): array
    {
        $refClass = new \ReflectionClass($controllerClass);
        $constructor = $refClass->getConstructor();
        $deps = [];

        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                // Key by parameter name so the container spreads the resolved
                // services as named arguments. Reflection already yields them
                // in signature order, but naming them keeps construction
                // correct even when a caller reorders or omits an optional
                // dependency. Hand-written config/controllers.php lists stay
                // positional: a list with integer keys spreads positionally.
                $name = $param->getName();
                $type = $param->getType();

                if ($param->isDefaultValueAvailable()) {
                    // Always prefer defaults
                    $deps[$name] = $param->getDefaultValue();
                } elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    // Class type → service
                    $deps[$name] = $type->getName();
                } elseif ($type instanceof \ReflectionNamedType && 'string' === $type->getName()) {
                    if ($type->allowsNull()) {
                        // ?string → %param% if available, else null
                        $deps[$name] = $this->container->hasParameter($name)
                            ? '%'.$name.'%'
                            : null;
                    } else {
                        // strict string → required parameter
                        $deps[$name] = '%'.$name.'%';
                    }
                } else {
                    $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
                    throw new \RuntimeException("Cannot resolve parameter \${$param->getName()} of type '{$typeName}' for controller '{$controllerClass}'.");
                }
            }
        }

        return $deps;
    }
}
