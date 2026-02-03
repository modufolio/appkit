<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Str;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

final class RelationManyToMany extends BaseCollectionRelation
{
    public function getTargetSetterMethodName(): string
    {
        return 'add' . Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getTargetPropertyName()));
    }

    public function getTargetRemoverMethodName(): string
    {
        return 'remove' . Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getTargetPropertyName()));
    }

    public static function createFromObject(ManyToManyInverseSideMapping|ManyToManyOwningSideMapping|array $mapping): self
    {
        // @legacy Remove conditional when ORM 2.x is no longer supported!
        if (\is_array($mapping)) {
            return new self(
                propertyName: $mapping['fieldName'],
                targetClassName: $mapping['targetEntity'],
                targetPropertyName: $mapping['mappedBy'],
                mapInverseRelation: !$mapping['isOwningSide'] || null !== $mapping['inversedBy'],
                isOwning: $mapping['isOwningSide'],
            );
        }

        if ($mapping instanceof ManyToManyOwningSideMapping) {
            return new self(
                propertyName: $mapping->fieldName,
                targetClassName: $mapping->targetEntity,
                targetPropertyName: $mapping->inversedBy,
                mapInverseRelation: (null !== $mapping->inversedBy),
                isOwning: $mapping->isOwningSide(),
            );
        }

        return new self(
            propertyName: $mapping->fieldName,
            targetClassName: $mapping->targetEntity,
            targetPropertyName: $mapping->mappedBy,
            mapInverseRelation: true,
            isOwning: false,
        );
    }
}
