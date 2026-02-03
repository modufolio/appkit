<?php

namespace Modufolio\Appkit\Resolver;

interface MapFilterInterface
{
    public static function fromArray(array $data): self;
}
