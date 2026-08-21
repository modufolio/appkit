<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Str;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
abstract class BaseCollectionRelation extends BaseRelation
{
    abstract public function getTargetSetterMethodName(): string;

    public function getAdderMethodName(): string
    {
        return 'add'.Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getPropertyName()));
    }

    public function getRemoverMethodName(): string
    {
        return 'remove'.Str::asCamelCase(Str::pluralCamelCaseToSingular($this->getPropertyName()));
    }
}
