<?php

namespace Modufolio\Appkit\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Check if the entity has the deleted_at field
        if (!$targetEntity->hasField('deletedAt')) {
            return '';
        }

        return $targetTableAlias . '.deleted_at IS NULL';
    }
}
