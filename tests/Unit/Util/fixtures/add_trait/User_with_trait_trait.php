<?php

namespace App\Entity;

use App\TestTrait;
use TraitAlreadyHere;

class User
{
    use TraitAlreadyHere;
    use TestTrait;
}
