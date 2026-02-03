<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Resolver\ParameterResolverInterface;
use Modufolio\Appkit\Resolver\TypeHintContainerResolver;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;


class TypeHintContainerResolverTest extends TestCase
{
    private ContainerInterface $container;
    private ParameterResolverInterface $resolver;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->resolver = new TypeHintContainerResolver($this->container);
    }

    public function testResolveTypeHintedParameter(): void
    {
        $reflection = new \ReflectionFunction(function (TestDependency $param) {});
        $providedParameters = [];
        $resolvedParameters = [];

        $this->container->expects($this->once())
            ->method('has')
            ->with(TestDependency::class)
            ->willReturn(true);

        $this->container->expects($this->once())
            ->method('get')
            ->with(TestDependency::class)
            ->willReturn(new TestDependency());

        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        $this->assertInstanceOf(TestDependency::class, $result['param']);
    }

    public function testSkipAlreadyResolvedParameter(): void
    {
        $reflection = new \ReflectionFunction(function (TestDependency $param) {});
        $providedParameters = [];
        $resolvedParameters = ['param' => new TestDependency()];

        $this->container->expects($this->never())->method('has');
        $this->container->expects($this->never())->method('get');

        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        $this->assertSame($resolvedParameters, $result);
    }

    public function testSkipNonTypeHintedParameter(): void
    {
        $reflection = new \ReflectionFunction(function ($param) {});
        $providedParameters = [];
        $resolvedParameters = [];

        $this->container->expects($this->never())->method('has');
        $this->container->expects($this->never())->method('get');

        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        $this->assertSame($resolvedParameters, $result);
    }

    public function testSkipBuiltinTypeParameter(): void
    {
        $reflection = new \ReflectionFunction(function (string $param) {});
        $providedParameters = [];
        $resolvedParameters = [];

        $this->container->expects($this->never())->method('has');
        $this->container->expects($this->never())->method('get');

        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        $this->assertSame($resolvedParameters, $result);
    }
}
