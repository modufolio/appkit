<?php

namespace Modufolio\Appkit\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class IsGranted
{
    public function __construct(public string|array $roles)
    {
        $this->roles = (array)$roles;
    }
}
