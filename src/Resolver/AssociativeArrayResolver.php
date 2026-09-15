<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

/**
 * @author    Matthieu Napoli
 * @author    Maarten Thiebou
 *
 * @see       https://github.com/PHP-DI/Invoker
 *
 * @copyright Matthieu Napoli
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class AssociativeArrayResolver implements ParameterResolverInterface
{
    /**
     * @param array<string, mixed> $providedParameters
     * @param array<string, mixed> $resolvedParameters
     *
     * @return array<string, mixed>
     */
    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        $parameters = $reflection->getParameters();

        // Skip parameters already resolved
        if (!empty($resolvedParameters)) {
            $parameters = array_filter($parameters, static fn ($param) => !array_key_exists($param->getName(), $resolvedParameters));
        }

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $providedParameters)) {
                $value = $providedParameters[$name];

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $resolvedParameters[$name] = $this->resolveObjectType($type->getName(), $value);
                } elseif ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                    $resolvedParameters[$name] = $this->convertToType($type->getName(), $value);
                } else {
                    $resolvedParameters[$name] = $value;
                }
            }
        }

        return $resolvedParameters;
    }

    private function resolveObjectType(string $type, mixed $value): mixed
    {
        if (enum_exists($type) && (is_int($value) || is_string($value))) {
            return $this->resolveEnum($type, $value);
        }

        // A value that already is the declared type is handed through; a
        // caller that provides an object by name has done the construction.
        return $value;
    }

    private function resolveEnum(string $enumClass, int|string $value): ?object
    {
        return $enumClass::tryFrom($value) ?? null;
    }

    /**
     * Coerce a route value to the parameter's scalar type when it reads as
     * one. A value that does not — `1.5` or `abc` for an int — is handed
     * through unchanged, so the call fails on the type rather than serving
     * `/posts/1.5` as post 1. Route requirements are the place to reject
     * such values with a 404 before they get here.
     */
    private function convertToType(string $type, mixed $value): mixed
    {
        if (!is_scalar($value)) {
            return $value;
        }

        return match ($type) {
            'int' => filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE) ?? $value,
            'float' => filter_var($value, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE) ?? $value,
            'bool' => filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
