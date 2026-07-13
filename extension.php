<?php

declare(strict_types=1);

use Modufolio\Appkit\PHPStan\Doctrine\ObjectMetadataResolver;
use Modufolio\Appkit\PHPStan\Reflection\Doctrine\EntityRepositoryClassReflectionExtension;
use Modufolio\Appkit\PHPStan\Rules\Doctrine\RepositoryMethodCallRule;

// Consumers point this at their entity manager by overriding the
// 'appkitDoctrine.objectMetadataResolver' service (same key) in their own
// phpstan.php, e.g.:
//
//     'services' => [
//         'appkitDoctrine.objectMetadataResolver' => [
//             'class' => ObjectMetadataResolver::class,
//             'arguments' => ['objectManagerLoader' => __DIR__.'/phpstan-object-manager.php'],
//         ],
//     ],
//
// where phpstan-object-manager.php is a plain script that `return`s a
// Doctrine\Persistence\ObjectManager (e.g. the app's EntityManager).
return [
    'services' => [
        'appkitDoctrine.objectMetadataResolver' => [
            'class' => ObjectMetadataResolver::class,
            'arguments' => [
                'objectManagerLoader' => null,
            ],
        ],
        [
            'class' => EntityRepositoryClassReflectionExtension::class,
            'tags' => ['phpstan.broker.methodsClassReflectionExtension'],
        ],
    ],
    'rules' => [
        RepositoryMethodCallRule::class,
    ],
];
