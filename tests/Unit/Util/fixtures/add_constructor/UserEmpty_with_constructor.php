<?php

namespace Modufolio\Appkit\Tests\App\Entity;

class User
{
    public function __construct(object $someObjectParam, string $someStringParam)
    {
        $this->someObjectParam = $someObjectParam;
        $this->someMethod($someStringParam);
    }
}
