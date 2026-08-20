<?php

namespace Modufolio\Appkit\Resolver;

interface MapFilterInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self;
}
