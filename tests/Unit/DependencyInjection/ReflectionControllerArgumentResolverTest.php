<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use PHPUnit\Framework\TestCase;

// Mock controllers for testing
class SimpleController
{
    public function __construct()
    {
    }
}

class ControllerWithDependency
{
    public function __construct(\stdClass $dependency)
    {
    }
}

class ControllerWithStringParam
{
    public function __construct(string $apiKey)
    {
    }
}

class ControllerWithNullableString
{
    public function __construct(?string $optionalConfig = null)
    {
    }
}

class ControllerWithDefault
{
    public function __construct(string $timeout = '30')
    {
    }
}

class ControllerWithMultipleDeps
{
    public function __construct(
        \stdClass $service,
        string $apiKey,
        int $timeout = 60
    ) {
    }
}

class MockContainer
{
    private array $parameters = [];

    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }
}

class ReflectionControllerArgumentResolverTest extends TestCase
{
    public function testResolveSimpleController(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(SimpleController::class);

        $this->assertEmpty($deps);
    }

    public function testResolveControllerWithClassDependency(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithDependency::class);

        $this->assertCount(1, $deps);
        $this->assertSame(\stdClass::class, $deps[0]);
    }

    public function testResolveControllerWithStringParameter(): void
    {
        $container = new MockContainer(['apiKey' => 'secret123']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithStringParam::class);

        $this->assertCount(1, $deps);
        $this->assertSame('%apiKey%', $deps[0]);
    }

    public function testResolveControllerWithNullableString(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithNullableString::class);

        $this->assertCount(1, $deps);
        $this->assertNull($deps[0]);
    }

    public function testResolveControllerWithNullableStringWhenParameterExists(): void
    {
        $container = new MockContainer(['optionalConfig' => 'value']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithNullableString::class);

        $this->assertCount(1, $deps);
        // Nullable string with no default value still gets null, not the parameter
        $this->assertNull($deps[0]);
    }

    public function testResolveControllerWithDefaultValue(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithDefault::class);

        $this->assertCount(1, $deps);
        $this->assertSame('30', $deps[0]);
    }

    public function testResolveControllerWithMultipleDependencies(): void
    {
        $container = new MockContainer(['apiKey' => 'secret']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithMultipleDeps::class);

        $this->assertCount(3, $deps);
        $this->assertSame(\stdClass::class, $deps[0]);
        $this->assertSame('%apiKey%', $deps[1]);
        $this->assertSame(60, $deps[2]); // Default value for int with default
    }

}
