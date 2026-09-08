<?php

declare(strict_types=1);

return [
    'home' => [
        'pattern' => '/',
        'controller' => ['App\\Controller\\PageController', 'home'],
    ],
    'page' => [
        'pattern' => '/{slug}',
        'controller' => ['App\\Controller\\PageController', 'show'],
        'requirements' => ['slug' => '[a-z0-9-]+'],
    ],
    'contact' => [
        'pattern' => '/contact',
        'methods' => ['GET', 'POST'],
        'controller' => ['App\\Controller\\PageController', 'contact'],
    ],
    // No string key: the pattern doubles as the name.
    [
        'pattern' => '/unnamed',
        'controller' => ['App\\Controller\\PageController', 'unnamed'],
    ],
];
