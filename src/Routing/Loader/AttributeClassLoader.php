<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Routing\Loader;

use Modufolio\Appkit\Attributes\IsGranted;
use Symfony\Component\Routing\Route;

class AttributeClassLoader extends \Symfony\Component\Routing\Loader\AttributeClassLoader
{
    /**
     * @template T of object
     *
     * @param \ReflectionClass<T> $class
     */
    protected function configureRoute(
        Route $route,
        \ReflectionClass $class,
        \ReflectionMethod $method,
        object $attr,
    ): void {
        $route->setDefault('_controller', [$class->name, $method->name]);
        $attributes = array_merge(
            $class->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF),
            $method->getAttributes(IsGranted::class, \ReflectionAttribute::IS_INSTANCEOF)
        );

        // Each #[IsGranted] is an independent requirement that must be satisfied
        // (AND between attributes); the roles within one attribute are alternatives
        // (OR). Keeping them grouped means a method-level #[IsGranted] tightens a
        // class-level one instead of widening it.
        $requiredRoleGroups = [];
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();

            $roles = array_values(array_filter(
                array_unique((array) $instance->roles),
                static fn (string $role): bool => '' !== $role,
            ));

            if ([] === $roles) {
                continue;
            }

            // A group is a plain role list when it applies to every method, and
            // a ['roles' => …, 'methods' => …] map when it is method-scoped.
            $requiredRoleGroups[] = [] === $instance->methods
                ? $roles
                : ['roles' => $roles, 'methods' => $instance->methods];
        }

        if (!empty($requiredRoleGroups)) {
            $route->setDefault('_is_granted_roles', $requiredRoleGroups);
        }
    }
}
