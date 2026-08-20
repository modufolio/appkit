<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

class ResolverPipeline implements ParameterResolverInterface
{
    /** @var list<ParameterResolverInterface> */
    private array $resolvers = [];

    public function addResolver(ParameterResolverInterface $resolver): self
    {
        $this->resolvers[] = $resolver;

        return $this;
    }

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
        foreach ($this->resolvers as $resolver) {
            $resolvedParameters = $resolver->getParameters($reflection, $providedParameters, $resolvedParameters);
            if (count($resolvedParameters) === count($reflection->getParameters())) {
                break; // All parameters resolved, stop processing
            }
        }

        return $resolvedParameters;
    }
}
