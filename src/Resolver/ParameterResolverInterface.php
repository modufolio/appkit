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
interface ParameterResolverInterface
{
    /**
     * Resolves the parameters to use to call the callable.
     *
     * `$resolvedParameters` contains parameters that have already been resolved.
     *
     * Each ParameterResolver must resolve parameters that are not already
     * in `$resolvedParameters`. That allows to chain multiple ParameterResolver.
     *
     * @param \ReflectionFunctionAbstract $reflection         reflection object for the callable
     * @param array<string, mixed>        $providedParameters parameters provided by the caller
     * @param array<string, mixed>        $resolvedParameters parameters resolved (keyed by parameter name)
     *
     * @return array<string, mixed>
     */
    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters,
    ): array;
}
