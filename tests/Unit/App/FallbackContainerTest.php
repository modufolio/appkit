<?php

namespace Modufolio\Appkit\Tests\Unit\App;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Exception\NotFoundException;
use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;

class FallbackContainerTest extends AppTestCase
{
    private App $container;

    protected function setUp(): void
    {
        $this->container = clone $this->app();
    }

    private function fallbackWith(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }

            public function get(string $id): mixed
            {
                if (!isset($this->services[$id])) {
                    throw new class("Not found: $id") extends \RuntimeException implements NotFoundExceptionInterface {};
                }

                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    public function testGetDelegatesToFallbackOnMiss(): void
    {
        $service = new \stdClass();
        $this->container->setFallbackContainer($this->fallbackWith(['some.service' => $service]));

        $this->assertTrue($this->container->has('some.service'));
        $this->assertSame($service, $this->container->get('some.service'));
    }

    public function testKernelServicesTakePrecedenceOverFallback(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith([
            EntityManagerInterface::class => new \stdClass(),
        ]));

        $this->assertInstanceOf(EntityManagerInterface::class, $this->container->get(EntityManagerInterface::class));
    }

    public function testUnknownIdStillThrowsNotFound(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith([]));

        $this->expectException(NotFoundException::class);
        $this->container->get('NonExistentClass');
    }

    public function testHasReturnsFalseForUnknownIdWithFallbackSet(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith([]));

        $this->assertFalse($this->container->has('NonExistentClass'));
    }

    public function testInterfaceValidationAppliesToDelegatedInstances(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith(['some.service' => new \stdClass()]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not implement required interface');

        $this->container->get('some.service', SerializerInterface::class);
    }

    public function testKernelClassGuardAppliesWithFallbackSet(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith([App::class => new \stdClass()]));

        $this->assertFalse($this->container->has(App::class));

        $this->expectException(\LogicException::class);
        $this->container->get(App::class);
    }

    public function testKernelCannotBeItsOwnFallback(): void
    {
        $this->expectException(\LogicException::class);
        $this->container->setFallbackContainer($this->container);
    }

    public function testFallbackCanBeUnset(): void
    {
        $this->container->setFallbackContainer($this->fallbackWith(['some.service' => new \stdClass()]));
        $this->container->setFallbackContainer(null);

        $this->assertFalse($this->container->has('some.service'));
    }
}
