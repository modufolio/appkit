<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Form\ValidationResult;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class AttributeParameterResolver implements ParameterResolverInterface
{
    /**
     * @param list<AttributeResolverInterface> $attributeResolvers
     */
    public function __construct(private array $attributeResolvers = [])
    {
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
        /** @var list<ValidationResult> $validationResults in payload order */
        $validationResults = [];
        /** @var list<\ReflectionParameter> $validationResultParameters in signature order */
        $validationResultParameters = [];

        foreach ($reflection->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $resolvedParameters)) {
                continue;
            }

            // A ValidationResult parameter is filled from a mapped payload
            // below, wherever it sits in the signature — before or after
            // the payload it belongs to.
            if ($this->acceptsValidationResult($parameter)) {
                $validationResultParameters[] = $parameter;
                continue;
            }

            foreach ($this->attributeResolvers as $resolver) {
                if (!$resolver->supports($parameter)) {
                    continue;
                }

                $result = $resolver->resolve($parameter, $providedParameters);

                if ($result instanceof ResolvedPayload) {
                    $resolvedParameters[$parameter->getName()] = $result->payload;
                    $validationResults[] = $result->validationResult;
                } else {
                    $resolvedParameters[$parameter->getName()] = $result;
                }

                break;
            }
        }

        // Pair results with parameters in order: the first ValidationResult
        // parameter receives the first mapped payload's result, and so on.
        foreach ($validationResultParameters as $index => $parameter) {
            if (isset($validationResults[$index])) {
                $resolvedParameters[$parameter->getName()] = $validationResults[$index];
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

        return ValidationResult::class === $type->getName();
    }
}
