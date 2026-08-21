<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Modufolio\Appkit\Console\Str;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class RelationOneToOne extends BaseRelation
{
    public function getTargetGetterMethodName(): string
    {
        return 'get'.Str::asCamelCase($this->requireTargetPropertyName());
    }

    public function getTargetSetterMethodName(): string
    {
        return 'set'.Str::asCamelCase($this->requireTargetPropertyName());
    }

    public static function createFromObject(OneToOneInverseSideMapping|OneToOneOwningSideMapping $mapping): self
    {
        if ($mapping instanceof OneToOneOwningSideMapping) {
            return new self(
                propertyName: $mapping->fieldName,
                targetClassName: $mapping->targetEntity,
                targetPropertyName: $mapping->inversedBy,
                mapInverseRelation: null !== $mapping->inversedBy,
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
