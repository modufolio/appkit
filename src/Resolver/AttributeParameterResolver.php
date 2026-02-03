<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

class AttributeParameterResolver implements ParameterResolverInterface
{
    public function __construct(private array $attributeResolvers = [])
    {
    }

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
            if (!array_key_exists($parameter->getName(), $resolvedParameters)) {
                foreach ($this->attributeResolvers as $resolver) {
                    if ($resolver->supports($parameter)) {
                        $resolvedParameters[$parameter->getName()] = $resolver->resolve($parameter, $providedParameters);
                        break;
                    }
                }
            }
        }

        return $resolvedParameters;
    }
}
