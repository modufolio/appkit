<?php

namespace Modufolio\Appkit\Tests\App\Entity;

use Modufolio\Appkit\Tests\App\TestTrait;
use Modufolio\Appkit\Tests\App\TraitAlreadyHere;

class User
{
    use TraitAlreadyHere;
    use TestTrait;
}
