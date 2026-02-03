<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Str;

abstract class BaseCollectionRelation extends BaseRelation
{
    abstract public function getTargetSetterMethodName(): string;

    public function getAdderMethodName(): string
    {
        return 'add' . Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getPropertyName()));
    }

    public function getRemoverMethodName(): string
    {
        return 'remove' . Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getPropertyName()));
    }
}
