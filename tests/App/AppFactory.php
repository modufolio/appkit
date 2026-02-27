<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Routing\Loader\AttributeClassLoader;
use Modufolio\Appkit\Tests\App\JsonApi\JsonApiController;
use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Routing\Loader\ArrayRouteLoader;
use Modufolio\Appkit\Routing\Loader\JsonApiRouteLoader;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Toolkit\F;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\PhpFileLoader;

class AppFactory
{
    public static function create(string $baseDir, ?string $env = null): AppInterface
    {
        $locator = new FileLocator([$baseDir . '/config']);
        $routeLoader = new DelegatingLoader(new LoaderResolver(
            [
                new PhpFileLoader($locator),
                new AttributeDirectoryLoader($locator, new AttributeClassLoader()),
                new ArrayRouteLoader($locator),
                new JsonApiRouteLoader($locator, JsonApiController::class),
            ]
        ));


        // Configure Security
        $securityConfigurator = new SecurityConfigurator();
        $securityClosure = require $baseDir . '/config/security.php';

        $securityClosure($securityConfigurator);


        return (new App(
            baseDir: $baseDir,
            routeLoader: $routeLoader,
            userProviderClass: UserRepository::class,
            authenticators: F::load($baseDir . '/config/authenticators.php', []),
            controllers: F::load($baseDir . '/config/controllers.php', []),
            factories: F::load($baseDir . '/config/factories.php', []),
            fileMap: [
                'doctrine' => $baseDir . '/config/test/doctrine.php',
                'interfaces' => $baseDir . '/config/interfaces.php',
            ],
            repositories: F::load($baseDir . '/config/repositories.php', []),
        ))->configureSecurity($securityConfigurator);
    }
}
