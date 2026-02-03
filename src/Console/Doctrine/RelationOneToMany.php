<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Str;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;

final class RelationOneToMany extends BaseCollectionRelation
{
    public function getTargetGetterMethodName(): string
    {
        return 'get' . Str::asCamelCase($this->getTargetPropertyName());
    }

    public function getTargetSetterMethodName(): string
    {
        return 'set' . Str::asCamelCase($this->getTargetPropertyName());
    }

    public function isMapInverseRelation(): bool
    {
        throw new \Exception('OneToMany IS the inverse side!');
    }

    public static function createFromObject(OneToManyAssociationMapping $mapping): self
    {
        return new self(
            propertyName: $mapping->fieldName,
            targetClassName: $mapping->targetEntity,
            targetPropertyName: $mapping->mappedBy,
            orphanRemoval: $mapping->orphanRemoval,
        );
    }
}
