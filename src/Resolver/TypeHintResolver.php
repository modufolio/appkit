<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

class TypeHintResolver implements ParameterResolverInterface
{
    public function getParameters(
        \Reflector $reflection,
        array $providedParameters,
        array $resolvedParameters
    ): array {
        $parameters = $reflection->getParameters();

        foreach ($parameters as $parameter) {
            // Skip if parameter is already resolved
            if (array_key_exists($parameter->getName(), $resolvedParameters)) {
                continue;
            }

            $parameterType = $parameter->getType();
            if (! $parameterType) {
                // No type
                continue;
            }
            if (! $parameterType instanceof \ReflectionNamedType) {
                // Union types are not supported
                continue;
            }
            if ($parameterType->isBuiltin()) {
                // Primitive types are not supported
                continue;
            }

            $parameterClass = $parameterType->getName();
            if ($parameterClass === 'self') {
                $parameterClass = $parameter->getDeclaringClass()->getName();
            }

            if (array_key_exists($parameterClass, $providedParameters)) {
                $resolvedParameters[$parameter->getName()] = $providedParameters[$parameterClass];
            }
        }

        return $resolvedParameters;
    }
}
