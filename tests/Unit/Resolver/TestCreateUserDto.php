<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Symfony\Component\Validator\Constraints as Assert;

class TestCreateUserDto
{
    public function __construct(
        #[Assert\NotBlank]
        public string $name = '',

        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',
    ) {
    }
}
