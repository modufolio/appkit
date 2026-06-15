<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

/**
 * Terminal pipeline stage that fills any still-unresolved parameter with its
 * signature default, or null when the parameter is nullable.
 *
 * This makes default handling explicit and lets nullable parameters resolve to
 * null instead of triggering an ArgumentCountError when nothing else resolves
 * them. Genuinely required parameters are left unresolved on purpose.
 */
class DefaultValueResolver implements ParameterResolverInterface
{
    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters,
    ): array {
        foreach ($reflection->getParameters() as $parameter) {
            if (array_key_exists($parameter->getName(), $resolvedParameters)) {
                continue;
            }

            // Variadics collect zero-or-more positional values; never inject one.
            if ($parameter->isVariadic()) {
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $resolvedParameters[$parameter->getName()] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $resolvedParameters[$parameter->getName()] = null;
            }
        }

        return $resolvedParameters;
    }
}
