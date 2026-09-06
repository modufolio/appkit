<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\DependencyInjection\Symfony\ContainerFactory;
use Modufolio\Appkit\Routing\Loader\ArrayRouteLoader;
use Modufolio\Appkit\Routing\Loader\AttributeClassLoader;
use Modufolio\Appkit\Routing\Loader\JsonApiRouteLoader;
use Modufolio\Appkit\Security\SecurityConfigurator;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Tests\App\Debug\RecordingProfiler;
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

    /** The test app's Symfony definitions, relative to the base directory as ContainerFactory expects. */
    public static function containerFile(string $baseDir): string
    {
        return substr(self::configDir($baseDir), \strlen($baseDir) + 1).'/container.php';
    }

    /**
     * Writable runtime directory for the test app.
     *
     * Under ParaTest each worker gets its own subdirectory, keyed by the
     * TEST_TOKEN it exports, so sessions and Doctrine proxies written by one
     * worker can never be read or unlinked by another.
     */
    public static function varDir(string $baseDir): string
    {
        $token = getenv('TEST_TOKEN');

        if (false === $token || '' === $token) {
            return $baseDir.'/var';
        }

        return $baseDir.'/var/test/'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $token);
    }

    /**
     * @param ContainerFactoryInterface|null $container the Symfony container behind the kernel; null for the default factory
     * @param string|null                    $varDir    a writable directory other than the shared test one
     */
    public static function create(string $baseDir, ?string $env = null, ?ContainerFactoryInterface $container = null, ?string $varDir = null): App
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

        // Configure Services
        $serviceConfigurator = new ServiceConfigurator();
        $servicesClosure = require $configDir.'/services.php';

        $servicesClosure($serviceConfigurator);

        $app = new App(
            baseDir: $baseDir,
            routeLoader: $routeLoader,
            logger: new NullLogger(),
            userProviderClass: UserRepository::class,
            authenticators: F::load($configDir.'/authenticators.php', []),
            controllers: F::load($configDir.'/controllers.php', []),
            fileMap: [
                'doctrine' => $configDir.'/test/doctrine.php',
            ],
            repositories: F::load($configDir.'/repositories.php', []),
        );

        $app->setVarDir($varDir ?? self::varDir($baseDir));
        // Modules first: the application's services.php must win for shared ids.
        // The Symfony container sits behind both, built during boot().
        $app->configureModules($configDir.'/modules.php')
            ->configureServices($serviceConfigurator)
            ->configureContainer($container ?? new ContainerFactory(self::containerFile($baseDir)))
            ->configureSecurity($securityConfigurator)
            ->boot();

        // JsonApiController isn't in config/controllers.php, so its
        // constructor is auto-wired by reflection; the untyped $configPath
        // string argument resolves to this container parameter by name.
        // Requires boot() to have run first (initializes $parameterBag).
        $app->setParameter('configPath', $configDir.'/json_api.php');

        // The profiling seam, installed the way a module would from boot().
        $app->setProfiler(new RecordingProfiler($app->stopwatch()));

        return $app;
    }
}
