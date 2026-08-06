<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\Routing\Loader\ArrayRouteLoader;
use Modufolio\Appkit\Routing\Loader\AttributeClassLoader;
use Modufolio\Appkit\Routing\Loader\JsonApiRouteLoader;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Tests\App\Entity\User;
use Modufolio\Appkit\Tests\App\JsonApi\JsonApiController;
use Modufolio\Appkit\Tests\App\Repository\UserRepository;
use Modufolio\Appkit\Toolkit\F;
use Psr\Log\NullLogger;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\PhpFileLoader;

class AppFactory
{
    public static function configDir(string $baseDir): string
    {
        return $baseDir.'/tests/fixtures/config';
    }

    public static function create(string $baseDir, ?string $env = null): App
    {
        // Allow the test User class to be unserialized from session-stored tokens.
        TokenUnserializer::register(User::class);

        $configDir = self::configDir($baseDir);

        $locator = new FileLocator([$configDir]);
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
        $securityClosure = require $configDir.'/security.php';

        $securityClosure($securityConfigurator);

        $app = new App(
            baseDir: $baseDir,
            routeLoader: $routeLoader,
            logger: new NullLogger(),
            userProviderClass: UserRepository::class,
            authenticators: F::load($configDir.'/authenticators.php', []),
            controllers: F::load($configDir.'/controllers.php', []),
            factories: F::load($configDir.'/factories.php', []),
            fileMap: [
                'doctrine' => $configDir.'/test/doctrine.php',
                'interfaces' => $configDir.'/interfaces.php',
            ],
            repositories: F::load($configDir.'/repositories.php', []),
        );

        $app->configureSecurity($securityConfigurator)->boot();

        // JsonApiController isn't in config/controllers.php, so its
        // constructor is auto-wired by reflection; the untyped $configPath
        // string argument resolves to this container parameter by name.
        // Requires boot() to have run first (initializes $parameterBag).
        $app->setParameter('configPath', $configDir.'/json_api.php');

        return $app;
    }
}
