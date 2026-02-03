<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

class ResolverPipeline implements ParameterResolverInterface
{
    private array $resolvers = [];

    public function addResolver(ParameterResolverInterface $resolver): self
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters
    ): array {
        foreach ($this->resolvers as $resolver) {
            $resolvedParameters = $resolver->getParameters($reflection, $providedParameters, $resolvedParameters);
            if (count($resolvedParameters) === count($reflection->getParameters())) {
                break; // All parameters resolved, stop processing
            }
        }
        return $resolvedParameters;
    }

}
