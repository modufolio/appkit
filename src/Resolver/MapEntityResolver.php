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
     * @throws \LogicException if the attribute or parameter is invalid
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
     * @throws \LogicException if the parameter type is not a valid class or entity
     * @throws ResourceNotFoundException if the entity is not found and the parameter is not nullable
     */
    private function resolveMapEntity(
        \ReflectionParameter $parameter,
        MapEntity $attribute,
        array $providedParameters,
    ): ?object {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf('Parameter "%s" must have a valid class type hint.', $parameter->getName()));
        }

        $entityClass = $type->getName();
        $criteria = $attribute->criteria;

        $id = $criteria['id'] ?? $providedParameters['id'] ?? null;

        $criteria = array_merge($criteria, ['id' => $id]);

        $object = $this->entityManager->getRepository($entityClass)->findOneBy($criteria);

        if ($object === null && !$parameter->allowsNull()) {
            throw new ResourceNotFoundException(sprintf('"%s" object not found by "%s".', $entityClass, self::class));
        }

        return $object;
    }
}
