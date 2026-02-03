<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\FindEntity;
use Doctrine\ORM\EntityManagerInterface;

class FindEntityResolver implements AttributeResolverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return !empty($parameter->getAttributes(FindEntity::class));
    }

    /**
     * Resolves the entity for the given parameter using the FindEntity attribute.
     *
     * @throws \LogicException if the attribute or parameter is invalid
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): ?object
    {
        $attributes = $parameter->getAttributes(FindEntity::class);

        if (empty($attributes)) {
            throw new \LogicException(sprintf(
                'Parameter "%s" does not have the required FindEntity attribute.',
                $parameter->getName()
            ));
        }

        $attributeInstance = $attributes[0]->newInstance();
        return $this->resolveFindEntity($parameter, $attributeInstance, $providedParameters);
    }

    /**
     * Resolves the entity based on the parameter type, criteria from the attribute, and provided parameters.
     *
     * @throws \LogicException if the parameter type is not a valid class or entity
     */
    private function resolveFindEntity(
        \ReflectionParameter $parameter,
        FindEntity $attribute,
        array $providedParameters
    ): ?object {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(sprintf(
                'Parameter "%s" must have a valid class type hint.',
                $parameter->getName()
            ));
        }

        $entityClass = $type->getName();
        $criteria = $attribute->criteria;

        // Check if an `id` is provided in the parameters
        $id = $criteria['id'] ?? $providedParameters['id'] ?? null;

        $criteria = array_merge($criteria, ['id' => $id]);

        $repository = $this->entityManager->getRepository($entityClass);

        return $repository->findOneBy($criteria);
    }
}
