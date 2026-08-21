<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Modufolio\Appkit\Console\Str;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class RelationManyToMany extends BaseCollectionRelation
{
    public function getTargetSetterMethodName(): string
    {
        return 'add'.Str::asCamelCase(Str::pluralCamelCaseToSingular($this->requireTargetPropertyName()));
    }

    public function getTargetRemoverMethodName(): string
    {
        return 'remove'.Str::asCamelCase(Str::pluralCamelCaseToSingular($this->requireTargetPropertyName()));
    }

    /**
     * @param ManyToManyInverseSideMapping|ManyToManyOwningSideMapping|array<string, mixed> $mapping
     */
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
                mapInverseRelation: null !== $mapping->inversedBy,
                isOwning: true,
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
