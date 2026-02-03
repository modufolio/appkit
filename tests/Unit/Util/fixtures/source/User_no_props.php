<?php

namespace Modufolio\Appkit\Tests\App\Entity;

use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
class User
// extra space to keep things interesting
{
    public function hello()
    {
        return 'hi there!';
    }
}
