<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Str;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;

final class RelationOneToOne extends BaseRelation
{
    public function getTargetGetterMethodName(): string
    {
        return 'get' . Str::asCamelCase($this->getTargetPropertyName());
    }

    public function getTargetSetterMethodName(): string
    {
        return 'set' . Str::asCamelCase($this->getTargetPropertyName());
    }

    public static function createFromObject(OneToOneInverseSideMapping|OneToOneOwningSideMapping $mapping): self
    {

        if ($mapping instanceof OneToOneOwningSideMapping) {
            return new self(
                propertyName: $mapping->fieldName,
                targetClassName: $mapping->targetEntity,
                targetPropertyName: $mapping->inversedBy,
                mapInverseRelation: (null !== $mapping->inversedBy),
                isOwning: true,
                isNullable: $mapping->joinColumns[0]->nullable ?? true,
            );
        }

        return new self(
            propertyName: $mapping->fieldName,
            targetClassName: $mapping->targetEntity,
            targetPropertyName: $mapping->mappedBy,
            mapInverseRelation: true,
            isOwning: false,
            isNullable: true,
        );
    }
}
