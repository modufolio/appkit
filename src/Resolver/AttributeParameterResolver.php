<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Form\ValidationResult;

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

        $hasPendingValidationResult = false;
        $pendingValidationResult = null;

        foreach ($parameters as $parameter) {
            if (array_key_exists($parameter->getName(), $resolvedParameters)) {
                continue;
            }

            // Inject a pending ValidationResult from a previous ResolvedPayload
            if ($hasPendingValidationResult && $this->acceptsValidationResult($parameter)) {
                $resolvedParameters[$parameter->getName()] = $pendingValidationResult;
                $hasPendingValidationResult = false;
                $pendingValidationResult = null;
                continue;
            }

            foreach ($this->attributeResolvers as $resolver) {
                if ($resolver->supports($parameter)) {
                    $result = $resolver->resolve($parameter, $providedParameters);

                    if ($result instanceof ResolvedPayload) {
                        $resolvedParameters[$parameter->getName()] = $result->payload;
                        $pendingValidationResult = $result->validationResult;
                        $hasPendingValidationResult = true;
                    } else {
                        $resolvedParameters[$parameter->getName()] = $result;
                    }

                    break;
                }
            }
        }

        return $resolvedParameters;
    }

    private function acceptsValidationResult(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        return $type->getName() === ValidationResult::class;
    }
}
