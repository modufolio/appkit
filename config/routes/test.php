<?php

declare(strict_types=1);

use Modufolio\Appkit\Tests\App\Controller\TestController;

return [
    // Test routes for authentication/firewall tests
    'login' => [
        'pattern' => '/login',
        'methods' => ['GET', 'POST'],
        'controller' => [TestController::class, 'login'],
    ],
    'logout' => [
        'pattern' => '/logout',
        'methods' => ['GET'],
        'controller' => [TestController::class, 'logout'],
    ],
    'home' => [
        'pattern' => '/',
        'methods' => ['GET'],
        'controller' => [TestController::class, 'index'],
    ],
    'public' => [
        'pattern' => '/public',
        'methods' => ['GET', 'POST'],
        'controller' => [TestController::class, 'public'],
    ],
    'submit' => [
        'pattern' => '/submit',
        'methods' => ['POST'],
        'controller' => [TestController::class, 'submit'],
    ],
    'api_me' => [
        'pattern' => '/api/me',
        'methods' => ['GET'],
        'controller' => [TestController::class, 'me'],
    ],
];
