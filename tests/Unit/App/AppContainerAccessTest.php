<?php

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Exception\NotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AppContainerAccessTest extends AppTestCase
{
    private App $container;

    protected function setUp(): void
    {
        $this->container = clone $this->app();
    }

    #[DataProvider('availableServices')]
    public function testAppHasReturnsTrueForAvailableServices(string $serviceId): void
    {
        $this->assertTrue($this->container->has($serviceId));
    }

    public static function availableServices(): array
    {
        return [
            [ContainerInterface::class],
            [EntityManagerInterface::class],
            [SerializerInterface::class],
            [ValidatorInterface::class],
        ];
    }

    public function testAppHasReturnsFalseForUnknownService(): void
    {
        $this->assertFalse($this->container->has('NonExistentClass'));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testAppGetReturnsValidServiceInstances(): void
    {
        $app = $this->container;

        $container = $app->get(ContainerInterface::class);
        $this->assertSame($app, $container);

        $this->assertInstanceOf(EntityManagerInterface::class, $app->get(EntityManagerInterface::class));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testAppGetThrowsExceptionForUnknownService(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Class or parameter NonExistentClass is not found.');

        $this->container->get('NonExistentClass');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testInterfaceTypeHinting(): void
    {
        $entityManager = $this->container->get(EntityManagerInterface::class, EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testContainerGetWithInvalidInterface(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service "Doctrine\ORM\EntityManager" does not implement required interface "Symfony\Component\Serializer\SerializerInterface".');

        $this->container->get(EntityManagerInterface::class, SerializerInterface::class);
    }
}
