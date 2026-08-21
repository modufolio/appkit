<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Util;

use Modufolio\Appkit\Console\Str;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class ClassNameValue implements \Stringable
{
    public function __construct(
        private string $typeHint,
        private string $fullClassName,
    ) {
    }

    public function getShortName(): string
    {
        if ($this->isSelf()) {
            return Str::getShortClassName($this->fullClassName);
        }

        return $this->typeHint;
    }

    public function isSelf(): bool
    {
        return 'self' === $this->typeHint;
    }

    public function __toString(): string
    {
        return $this->getShortName();
    }
}
