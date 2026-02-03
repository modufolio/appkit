<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

interface AttributeResolverInterface
{
    public function supports(\ReflectionParameter $parameter): bool;

    public function resolve(\ReflectionParameter $parameter, array $providedParameters);
}
