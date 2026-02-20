<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Entity\Contact;
use Modufolio\Appkit\Tests\App\Entity\Organization;
use Modufolio\Appkit\Tests\App\Entity\User;

return [
    User::class => [
        'fields' => [
            'email' => fn ($faker) => $faker->unique()->safeEmail(),
            'password' => fn () => password_hash('secret', PASSWORD_BCRYPT),
            'roles' => fn () => [],
        ],
    ],

    Contact::class => [
        'fields' => [
            'firstName' => fn ($faker) => $faker->firstName(),
            'lastName' => fn ($faker) => $faker->lastName(),
            'email' => fn ($faker) => $faker->unique()->safeEmail(),
            'phone' => fn ($faker) => $faker->phoneNumber(),
            'address' => fn ($faker) => $faker->streetAddress(),
            'city' => fn ($faker) => $faker->city(),
            'region' => fn ($faker) => $faker->state(),
            'country' => 'US',
            'postalCode' => fn ($faker) => $faker->postcode()
        ]

    ],

    Organization::class => [
        'fields' => [
            'name' => fn ($faker) => $faker->company(),
            'email' => fn ($faker) => $faker->companyEmail(),
            'phone' => fn ($faker) => $faker->phoneNumber(),
            'address' => fn ($faker) => $faker->streetAddress(),
            'city' => fn ($faker) => $faker->city(),
            'region' => fn ($faker) => $faker->state(),
            'country' => 'US',
            'postalCode' => fn ($faker) => $faker->postcode(),
            'account' => [
                'name' => fn ($faker) => $faker->company(),
            ],
        ]
    ],
];
