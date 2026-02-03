<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Routing\Loader;

use Modufolio\Appkit\Attributes\IsGranted;
use Symfony\Component\Routing\Route;

class AttributeClassLoader extends \Symfony\Component\Routing\Loader\AttributeClassLoader
{
    protected function configureRoute(
        Route $route,
        \ReflectionClass $class,
        \ReflectionMethod $method,
        object $attr
    ): void {
        $route->setDefault('_controller', [$class->name, $method->name]);
        $attributes = array_merge(
            $class->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF),
            $method->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF)
        );
        $requiredRoles = [];
        foreach ($attributes as $attribute) {
            $isGranted = $attribute->newInstance();
            $requiredRoles = array_merge($requiredRoles, (array)$isGranted->roles);
        }
        if (!empty($requiredRoles)) {
            $route->setDefault('_is_granted_roles', array_unique($requiredRoles));
        }
    }
}
