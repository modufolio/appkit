<?php

declare(strict_types = 1);

use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\Entity\UserTotpSecret;
use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Modufolio\Appkit\Tests\App\Repository\UserTotpSecretRepository;

return [
    UserRepository::class => User::class,
    UserTotpSecretRepository::class => UserTotpSecret::class,
];
