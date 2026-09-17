<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ContainerFactoryInterface;
use Modufolio\Appkit\DependencyInjection\Symfony\ContainerFactory;
use Modufolio\Appkit\Tests\App\AppFactory;
use Modufolio\Appkit\Tests\App\GreetingDispatched;
use Modufolio\Appkit\Tests\App\RecordingEventDispatcher;
use Modufolio\Appkit\Tests\App\Symfony\InvokableGreetingListener;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Listeners wired by the container rather than by a registration call:
 * #[AsEventListener] and EventSubscriberInterface, turned into tags by
 * autoconfiguration and into addListener() calls by Symfony's
 * RegisterListenersPass, at compile time.
 */
class ContainerEventListenersTest extends AppTestCase
{
    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = $this->app()->get(ContainerFactoryInterface::DISPATCHER_ID);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    public function testTheContainerPublishesADispatcherUnderSymfonysOwnId(): void
    {
        $this->assertInstanceOf(EventDispatcher::class, $this->dispatcher());
    }

    public function testAnAttributedListenerIsWiredWithoutAnyRegistrationCall(): void
    {
        $event = $this->dispatcher()->dispatch(new GreetingDispatched('Ada'));

        $this->assertInstanceOf(GreetingDispatched::class, $event);
        $this->assertContains('invokable', $event->seen, 'The bare attribute on an invokable class.');
        $this->assertContains('method', $event->seen, 'The attribute on a single method.');
        $this->assertContains('subscriber', $event->seen, 'EventSubscriberInterface, autoconfigured.');
    }

    public function testTheEventComesFromTheParameterTypeAndPriorityOrdersTheRun(): void
    {
        $event = $this->dispatcher()->dispatch(new GreetingDispatched('Ada'));
        $this->assertInstanceOf(GreetingDispatched::class, $event);

        // Only listeners for this event ran, highest priority first.
        $this->assertSame(
            ['early', 'subscriber', 'invokable', 'method', 'three:name-ok', 'late'],
            $event->seen,
            'Declared priorities first (10, 5), then the defaults in registration order, then -10.'
        );
    }

    public function testListenersAreResolvedAtCompileTimeNotByScanningAtRuntime(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());
        $builder->compile();

        $calls = $builder->getDefinition(ContainerFactoryInterface::DISPATCHER_ID)->getMethodCalls();
        $events = array_map(static fn (array $call) => $call[1][0], $calls);

        $this->assertNotSame([], $calls);
        $this->assertSame(['addListener'], array_values(array_unique(array_map(static fn (array $call) => $call[0], $calls))));
        $this->assertContains(GreetingDispatched::class, $events, 'The wiring is baked into the definition.');
    }

    public function testThePsr14InterfaceResolvesToTheDispatcherCarryingTheListeners(): void
    {
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());

        $this->assertTrue($builder->hasAlias(EventDispatcherInterface::class));
        $this->assertSame(
            ContainerFactoryInterface::DISPATCHER_ID,
            (string) $builder->getAlias(EventDispatcherInterface::class),
            'Aliased, so the bridge leaves the id alone and autowiring never lands back on the kernel.'
        );
    }

    public function testInsideTheContainerTheInterfaceAndTheIdAreOneInstance(): void
    {
        // Within the Symfony graph the alias decides, so a service that
        // autowires EventDispatcherInterface gets the dispatcher carrying the
        // listeners. Through the kernel the declaration in services.php still
        // answers first, which is the case below.
        $builder = (new ContainerFactory(AppFactory::containerFile($this->app()->baseDir)))->build($this->app());
        $builder->compile();
        $builder->set(ContainerFactory::KERNEL_ID, $this->app());

        $this->assertSame(
            $builder->get(ContainerFactoryInterface::DISPATCHER_ID),
            $builder->get(EventDispatcherInterface::class),
        );
    }

    public function testAnExplicitDeclarationInServicesStillOwnsTheKernelsDispatcher(): void
    {
        // The test app declares EventDispatcherInterface in config/services.php.
        // That wins over the container's, so an application can take it back.
        $this->assertInstanceOf(RecordingEventDispatcher::class, $this->app()->eventDispatcher());
        $this->assertNotSame($this->dispatcher(), $this->app()->eventDispatcher());
    }

    public function testTheKernelAdoptsTheContainersDispatcherWithoutSuchADeclaration(): void
    {
        $app = AppFactory::create($this->app()->baseDir);
        $app->forgetDeclaredEventDispatcher();

        $this->assertSame(
            $app->get(ContainerFactoryInterface::DISPATCHER_ID),
            $app->eventDispatcher(),
            'No declaration: the kernel takes the one the pass wired.'
        );

        $event = $app->eventDispatcher()->dispatch(new GreetingDispatched('Ada'));
        $this->assertInstanceOf(GreetingDispatched::class, $event);
        $this->assertContains('invokable', $event->seen);
    }

    public function testListenersSurviveTheDumpedContainer(): void
    {
        $varDir = sys_get_temp_dir().'/appkit-events-'.bin2hex(random_bytes(4));

        try {
            $app = AppFactory::create(
                $this->app()->baseDir,
                null,
                new ContainerFactory(AppFactory::containerFile($this->app()->baseDir), dump: true),
                $varDir,
            );

            $dispatcher = $app->get(ContainerFactoryInterface::DISPATCHER_ID);
            $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

            $event = $dispatcher->dispatch(new GreetingDispatched('Ada'));
            $this->assertInstanceOf(GreetingDispatched::class, $event);
            $this->assertSame(['early', 'subscriber', 'invokable', 'method', 'three:name-ok', 'late'], $event->seen);

            $files = glob($app->cacheDir().'/container/AppkitContainer_*.php') ?: [];
            $this->assertCount(1, $files);
            $this->assertStringContainsString(
                InvokableGreetingListener::class,
                (string) file_get_contents($files[0]),
                'Compile-time wiring, nothing scanned at runtime.'
            );
        } finally {
            if (is_dir($varDir)) {
                exec('rm -rf '.escapeshellarg($varDir));
            }
        }
    }
}
