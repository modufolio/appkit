<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Util;

use Modufolio\Appkit\Console\Str;

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
