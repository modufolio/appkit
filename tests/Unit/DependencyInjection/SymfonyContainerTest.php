<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\DependencyInjection\Symfony\ContainerFactory;
use Modufolio\Appkit\DependencyInjection\Symfony\UserProviderPass;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Security\User\UserProviderInterface;
use Modufolio\Appkit\Tests\App\AppFactory;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoCounter;
use Modufolio\Appkit\Tests\App\Module\Demo\DemoSymfonyService;
use Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection\GreetingRegistry;
use Modufolio\Appkit\Tests\App\Symfony\Greeter;
use Modufolio\Appkit\Tests\App\Symfony\GreeterController;
use Modufolio\Appkit\Tests\App\Symfony\GreeterEndpoint;
use Modufolio\Appkit\Tests\App\Symfony\TaggedUserProvider;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The Symfony container behind the kernel, as the test app opts in through
 * AppFactory: config/container.php for the application, config/container.php
 * per module, and the bridge that shows the kernel's services to both.
 */
class SymfonyContainerTest extends AppTestCase
{
    public function testASymfonyServiceResolvesThroughTheKernel(): void
    {
        $greeter = $this->app()->get(Greeter::class);

        $this->assertInstanceOf(Greeter::class, $greeter);
        $this->assertSame('Hello, Ada (25 per page)', $greeter->greet('Ada'));
    }

    public function testTheBridgeHandsOutTheKernelsOwnServices(): void
    {
        $greeter = $this->app()->get(Greeter::class);
        $this->assertInstanceOf(Greeter::class, $greeter);

        // A core service, by the interface the kernel declares it under.
        $this->assertSame($this->app()->entityManager(), $greeter->entityManager);
        // A module service the kernel declares — with the manifest config merged.
        $this->assertInstanceOf(DemoCounter::class, $greeter->counter);
        $this->assertSame(25, $greeter->counter->perPage);
    }

    public function testTheKernelKeepsFirstSayOnItsOwnIds(): void
    {
        // DemoCounter is a kernel set() service: fresh on every get(), even
        // though the Symfony graph also knows the id through the bridge.
        $this->assertNotSame($this->app()->get(DemoCounter::class), $this->app()->get(DemoCounter::class));
        $this->assertTrue($this->app()->has(Greeter::class), 'has() sees the fallback container.');
    }

    public function testAControllerTheSymfonyContainerKnowsIsBuiltThere(): void
    {
        $controller = $this->app()->getController(GreeterController::class);

        $this->assertInstanceOf(GreeterController::class, $controller);
        $this->assertInstanceOf(Greeter::class, $controller->greeter, 'Autowired by Symfony, public by the Controller suffix.');
        $this->assertSame($this->app(), $controller->app, 'AppAware still applies to a container-built controller.');
        $this->assertSame($controller, $this->app()->getController(GreeterController::class), 'Cached per request like any controller.');
    }

    public function testAModuleContributesToTheSymfonyContainerLikeABundle(): void
    {
        $service = $this->app()->get(DemoSymfonyService::class);

        $this->assertInstanceOf(DemoSymfonyService::class, $service);
        // From '%module.demo.per_page%' / '%module.demo.flavor%': manifest over defaults.
        $this->assertSame(25, $service->perPage);
        $this->assertSame('plain', $service->flavor);
    }

    public function testAModulesCompilerPassCollectsTaggedServicesAcrossBoundaries(): void
    {
        // The demo module owns the tag, the pass and the registry; the
        // application's container.php tags Greeter, the module's own
        // container.php tags DemoSymfonyService. Neither side names the other.
        $registry = $this->app()->get(GreetingRegistry::class);

        $this->assertInstanceOf(GreetingRegistry::class, $registry);
        $this->assertSame([DemoSymfonyService::class, Greeter::class], $registry->classes());
        $this->assertSame($this->app()->get(Greeter::class), $registry->greetings[1], 'Same shared instance the kernel hands out within the request.');
    }

    public function testResetDropsSymfonyServiceInstancesBetweenRequests(): void
    {
        $before = $this->app()->get(Greeter::class);
        $this->assertSame($before, $this->app()->get(Greeter::class), 'Shared within a request.');

        $this->app()->resetModules();

        $this->assertNotSame($before, $this->app()->get(Greeter::class), 'Rebuilt after reset().');
    }

    public function testAnUnknownIdSuggestsSymfonyIdsToo(): void
    {
        try {
            $this->app()->get('Modufolio\Appkit\Tests\App\Symfony\Greter');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString(Greeter::class, $e->getMessage());
        }
    }

    public function testTheBuilderCarriesKernelParametersAndTheModuleConfig(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());

        $this->assertInstanceOf(ContainerBuilder::class, $builder);
        $this->assertSame($this->app()->baseDir, $builder->getParameter('kernel.base_dir'));
        $this->assertSame('test', $builder->getParameter('kernel.environment'));
        $this->assertTrue($builder->getParameter('kernel.debug'));
        $this->assertSame(['per_page' => 25, 'flavor' => 'plain'], $builder->getParameter('module.demo'));
        $this->assertSame(25, $builder->getParameter('module.demo.per_page'));
        $this->assertSame('plain', $builder->getParameter('module.demo.built_with'), 'The optional build() hook ran with the merged config.');
        $this->assertTrue($builder->hasDefinition(ContainerFactory::KERNEL_ID));
        $this->assertTrue($builder->hasDefinition(\Doctrine\ORM\EntityManagerInterface::class), 'Core services are bridged.');
        $this->assertFalse($builder->getDefinition(\Doctrine\ORM\EntityManagerInterface::class)->isShared(), 'Bridged services defer sharing to the kernel.');
    }

    public function testTheKernelIsNeverBridgedUnderItsOwnType(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());

        $this->assertFalse($builder->has($this->app()::class));
        $this->assertFalse($builder->has(\Modufolio\Appkit\Core\AppInterface::class));
    }

    public function testADumpedContainerIsWrittenOnceAndLoadedFromTheCache(): void
    {
        $varDir = sys_get_temp_dir().'/appkit-symfony-'.bin2hex(random_bytes(4));
        $factory = new ContainerFactory(AppFactory::containerFile($this->app()->baseDir), dump: true);

        try {
            $app = AppFactory::create($this->app()->baseDir, null, $factory, $varDir);

            $files = glob($app->cacheDir().'/container/AppkitContainer_*.php') ?: [];
            $this->assertCount(1, $files, 'One compiled class per cache location.');

            $greeter = $app->get(Greeter::class);
            $this->assertInstanceOf(Greeter::class, $greeter);
            $this->assertSame('Hello, Ada (25 per page)', $greeter->greet('Ada'));

            // The compile-time result survived the dump: the pass ran once,
            // and the dumped class carries the collected references.
            $registry = $app->get(GreetingRegistry::class);
            $this->assertInstanceOf(GreetingRegistry::class, $registry);
            $this->assertSame([DemoSymfonyService::class, Greeter::class], $registry->classes());
            $this->assertStringContainsString('GreetingRegistry', (string) file_get_contents($files[0]));

            // A second create() in the same process reuses the class and the file.
            $again = $factory->create($app);
            $this->assertInstanceOf(Container::class, $again);
            $this->assertInstanceOf(Greeter::class, $again->get(Greeter::class));
            $this->assertCount(1, glob($app->cacheDir().'/container/AppkitContainer_*.php') ?: []);
        } finally {
            if (is_dir($varDir)) {
                exec('rm -rf '.escapeshellarg($varDir));
            }
        }
    }

    public function testADumpedContainerIsRebuiltWhenTheModuleSetChanges(): void
    {
        $varDir = sys_get_temp_dir().'/appkit-symfony-'.bin2hex(random_bytes(4));
        $factory = new ContainerFactory(AppFactory::containerFile($this->app()->baseDir), dump: true);

        try {
            $app = AppFactory::create($this->app()->baseDir, null, $factory, $varDir);

            [$file] = glob($app->cacheDir().'/container/AppkitContainer_*.php') ?: [null];
            $this->assertNotNull($file);
            $manifest = $file.'.manifest';
            $this->assertFileExists($manifest, 'The module-set hash sits beside the compiled class.');
            $this->assertSame($factory->manifestHash($app), trim((string) file_get_contents($manifest)));

            // Same module set: the class is left alone (the class is already
            // loaded, so a marker in the file is invisible to the runtime).
            $sentinel = '// not rewritten';
            file_put_contents($file, "\n".$sentinel."\n", \FILE_APPEND);
            $factory->create($app);
            $this->assertStringContainsString($sentinel, (string) file_get_contents($file), 'A fresh cache is not rewritten.');

            // A class dumped from another module set (the sidecar disagrees)
            // is rebuilt even though ConfigCache still calls it fresh.
            file_put_contents($manifest, 'another-module-set');
            $factory->create($app);
            $this->assertStringNotContainsString($sentinel, (string) file_get_contents($file), 'A changed module set rebuilds the class.');
            $this->assertSame($factory->manifestHash($app), trim((string) file_get_contents($manifest)));
        } finally {
            if (is_dir($varDir)) {
                exec('rm -rf '.escapeshellarg($varDir));
            }
        }
    }

    public function testTheControllerTagKeepsAControllerReachableWhenEverythingElseIsPrivate(): void
    {
        $app = AppFactory::create($this->app()->baseDir, null, new ContainerFactory(AppFactory::containerFile($this->app()->baseDir), public: false));
        $app->initializeConsoleState();

        $endpoint = $app->getController(GreeterEndpoint::class);
        $this->assertInstanceOf(GreeterEndpoint::class, $endpoint, 'Public through the appkit.controller tag, not the suffix.');
        $this->assertSame('Hello, Ada (25 per page)', $endpoint->greeter->greet('Ada'), 'Autowired like any Symfony service.');
        $this->assertInstanceOf(GreeterController::class, $app->getController(GreeterController::class), 'The suffix still counts.');

        // Greeter is only ever injected, so the compiler kept it private:
        // fetching it by id says exactly that instead of guessing a typo.
        try {
            $app->get(Greeter::class);
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('private', $e->getMessage());
            $this->assertStringContainsString('appkit.controller', $e->getMessage());
            $this->assertStringNotContainsString('Did you mean', $e->getMessage());
        }

        // And asking for it as a controller fails loudly rather than letting
        // reflection rebuild it behind the definition's back.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('private');
        $app->getController(Greeter::class);
    }

    public function testATaggedServiceBecomesTheUserProviderInTheSymfonyGraph(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());
        $builder->register(TaggedUserProvider::class, TaggedUserProvider::class)->addTag(UserProviderPass::TAG);
        $builder->compile();

        // Symfony side: the alias replaced the bridged definition.
        $this->assertSame(TaggedUserProvider::class, (string) $builder->getAlias(UserProviderInterface::class));
        $this->assertInstanceOf(TaggedUserProvider::class, $builder->get(ContainerFactoryInterface::USER_PROVIDER_ID));

        // Kernel side: the published id is what an application's userProvider() fetches.
        $provider = $builder->get(ContainerFactoryInterface::USER_PROVIDER_ID);
        $this->assertInstanceOf(UserProviderInterface::class, $provider);
        $this->assertSame('ada', $provider->loadUserByIdentifier('ada')->getUserIdentifier());
    }

    public function testTwoTaggedUserProvidersFailAtCompileTime(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());
        $builder->register('first', TaggedUserProvider::class)->addTag(UserProviderPass::TAG);
        $builder->register('second', TaggedUserProvider::class)->addTag(UserProviderPass::TAG);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('first, second');
        $builder->compile();
    }
}
