<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Modufolio\Appkit\Console\Str;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class RelationOneToMany extends BaseCollectionRelation
{
    public function getTargetGetterMethodName(): string
    {
        return 'get'.Str::asCamelCase($this->requireTargetPropertyName());
    }

    public function getTargetSetterMethodName(): string
    {
        return 'set'.Str::asCamelCase($this->requireTargetPropertyName());
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
