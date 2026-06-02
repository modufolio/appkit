<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

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
     * @param array                       $providedParameters parameters provided by the caller
     * @param array                       $resolvedParameters parameters resolved (indexed by parameter position)
     */
    public function getParameters(
        \ReflectionFunctionAbstract $reflection,
        array $providedParameters,
        array $resolvedParameters,
    ): array;
}
