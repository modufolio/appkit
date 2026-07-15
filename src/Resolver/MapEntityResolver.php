<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Attributes\MapEntity;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class MapEntityResolver implements AttributeResolverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return !empty($parameter->getAttributes(MapEntity::class));
    }

    /**
     * Resolves the entity for the given parameter using the MapEntity attribute.
     * Throws a 404 if the entity is not found and the parameter is not nullable.
     *
     * @throws \LogicException           if the attribute or parameter is invalid
     * @throws ResourceNotFoundException if the entity is not found and the parameter is not nullable
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): ?object
    {
        $attributes = $parameter->getAttributes(MapEntity::class);

        if (empty($attributes)) {
            throw new \LogicException(sprintf('Parameter "%s" does not have the required MapEntity attribute.', $parameter->getName()));
        }

        $attributeInstance = $attributes[0]->newInstance();

        return $this->resolveMapEntity($parameter, $attributeInstance, $providedParameters);
    }

    /**
     * @throws \LogicException           if the parameter type is not a valid class or no criteria can be built
     * @throws ResourceNotFoundException if the entity is not found and the parameter is not nullable
     */
    private function resolveMapEntity(
        \ReflectionParameter $parameter,
        MapEntity $attribute,
        array $providedParameters,
    ): ?object {
        $entityClass = $attribute->class ?? $this->entityClassFromType($parameter);

        $criteria = $attribute->criteria;

        // Translate route parameters into entity field criteria.
        foreach ($attribute->mapping ?? [] as $routeParam => $field) {
            if (array_key_exists($routeParam, $providedParameters)) {
                $criteria[$field] = $providedParameters[$routeParam];
            }
        }

        // Use the route's id as a primary-key criterion when one is available.
        $id = $criteria['id'] ?? $providedParameters['id'] ?? null;
        if (null !== $id) {
            $criteria['id'] = $id;
        }

        foreach ($attribute->exclude ?? [] as $field) {
            unset($criteria[$field]);
        }

        if ($attribute->stripNull) {
            $criteria = array_filter($criteria, static fn ($value) => null !== $value);
        }

        if ([] === $criteria) {
            throw new \LogicException(sprintf('Cannot resolve entity "%s" for parameter "%s": no id or criteria available.', $entityClass, $parameter->getName()));
        }

        $object = $this->entityManager->getRepository($entityClass)->findOneBy($criteria);

        if (null === $object && !$parameter->allowsNull()) {
            throw new ResourceNotFoundException($attribute->message ?? sprintf('"%s" object not found by "%s".', $entityClass, self::class));
        }

        return $object;
    }

    /**
     * @throws \LogicException if the parameter does not have a valid class type hint
     */
    private function entityClassFromType(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf('Parameter "%s" must have a valid class type hint or an explicit MapEntity class.', $parameter->getName()));
        }

        return $type->getName();
    }
}
