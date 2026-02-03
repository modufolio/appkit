<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Entity\User;

return [
    User::class => [
        'fields' => [
            'email' => fn ($faker) => $faker->unique()->safeEmail(),
            'password' => fn () => password_hash('secret', PASSWORD_BCRYPT),
            'roles' => fn () => [],
        ],
    ],
];
