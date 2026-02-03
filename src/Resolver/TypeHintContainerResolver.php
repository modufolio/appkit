<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionNamedType;

readonly class TypeHintContainerResolver implements ParameterResolverInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters
    ): array {
        $parameters = $reflection->getParameters();

        // Skip parameters already resolved
        if (! empty($resolvedParameters)) {
            $parameters = array_diff_key($parameters, $resolvedParameters);
        }

        foreach ($parameters as $parameter) {
            $parameterType = $parameter->getType();
            if (array_key_exists($parameter->getName(), $resolvedParameters)) {
                // Skip parameters already resolved
                continue;
            }
            if (! $parameterType) {
                // No type
                continue;
            }
            if (! $parameterType instanceof ReflectionNamedType) {
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

            if ($this->container->has($parameterClass)) {
                $resolvedParameters[$parameter->getName()] = $this->container->get($parameterClass);
            }
        }

        return $resolvedParameters;
    }
}
