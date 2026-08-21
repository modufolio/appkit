<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class RelationManyToOne extends BaseRelation
{
    public static function createFromObject(ManyToOneAssociationMapping $mapping): self
    {
        return new self(
            propertyName: $mapping->fieldName,
            targetClassName: $mapping->targetEntity,
            targetPropertyName: $mapping->inversedBy,
            mapInverseRelation: null !== $mapping->inversedBy,
            isOwning: true,
            isNullable: $mapping->joinColumns[0]->nullable ?? true,
        );
    }
}
