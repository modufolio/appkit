<?php

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Resolver\TypeHintResolver;
use PHPUnit\Framework\TestCase;

class TypeHintResolverTest extends TestCase
{
    private TypeHintResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TypeHintResolver();
    }

    public function testResolvesClassTypeHint(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(TestDependency $dependency): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $providedParameters = [TestDependency::class => new TestDependency()];
        $resolvedParameters = [];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertArrayHasKey('dependency', $result);
        $this->assertInstanceOf(TestDependency::class, $result['dependency']);
        $this->assertSame($providedParameters[TestDependency::class], $result['dependency']);
    }

    public function testSkipsPrimitiveTypeHint(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(string $value): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $providedParameters = ['string' => 'test'];
        $resolvedParameters = [];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertEmpty($result);
    }

    public function testSkipsUnionTypeHint(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(TestDependency|string $param): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $providedParameters = [TestDependency::class => new TestDependency()];
        $resolvedParameters = [];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertEmpty($result);
    }

    public function testResolvesSelfTypeHint(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(self $self): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $className = get_class($testClass);
        $providedParameters = [$className => $testClass];
        $resolvedParameters = [];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertArrayHasKey('self', $result);
        $this->assertSame($testClass, $result['self']);
    }

    public function testSkipsAlreadyResolvedParameters(): void
    {
        // Arrange
        $testClass = new class () {
            public function method(TestDependency $dependency): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $providedParameters = [TestDependency::class => new TestDependency()];
        $resolvedParameters = ['dependency' => new TestDependency()];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertSame($resolvedParameters, $result);
    }

    public function testSkipsParameterWithoutTypeHint(): void
    {
        // Arrange
        $testClass = new class () {
            public function method($param): void
            {
            }
        };
        $reflection = new \ReflectionMethod($testClass, 'method');
        $providedParameters = ['param' => 'value'];
        $resolvedParameters = [];

        // Act
        $result = $this->resolver->getParameters($reflection, $providedParameters, $resolvedParameters);

        // Assert
        $this->assertEmpty($result);
    }
}

// Helper class for testing
class TestDependency
{
}
