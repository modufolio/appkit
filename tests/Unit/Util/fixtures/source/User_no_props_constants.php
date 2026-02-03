<?php

namespace Modufolio\Appkit\Tests\App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class User
{
    public const FOO = 'bar';

    /**
     * Hi!
     */
    public const BAR = 'bar';

    /**
     * @return string
     */
    public function hello()
    {
        return 'hi there!';
    }
}
