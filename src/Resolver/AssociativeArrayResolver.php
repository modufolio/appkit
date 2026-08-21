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

        if (class_exists($type) && is_a($value, $type, true)) {
            return new $type($value);
        }

        return $value;
    }

    private function resolveEnum(string $enumClass, int|string $value): ?object
    {
        return $enumClass::tryFrom($value) ?? null;
    }

    private function convertToType(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }
}
