<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface AttributeResolverInterface
{
    public function supports(\ReflectionParameter $parameter): bool;

    /**
     * @param array<string, mixed> $providedParameters
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): mixed;
}
