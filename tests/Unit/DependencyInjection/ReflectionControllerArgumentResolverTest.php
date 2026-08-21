<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\DependencyInjection;

use Modufolio\Appkit\DependencyInjection\ParameterAccessorInterface;
use Modufolio\Appkit\DependencyInjection\ReflectionControllerArgumentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
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
        int $timeout = 60,
    ) {
    }
}

class MockContainer implements ParameterAccessorInterface
{
    /** @var array<string, mixed> */
    private array $parameters = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters = [])
    {
        $this->parameters = $parameters;
    }

    public function hasParameter(string $name): bool
    {
        return isset($this->parameters[$name]);
    }
}

#[CoversClass(ReflectionControllerArgumentResolver::class)]
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
        $this->assertSame(\stdClass::class, $deps['dependency']);
    }

    public function testResolveControllerWithStringParameter(): void
    {
        $container = new MockContainer(['apiKey' => 'secret123']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithStringParam::class);

        $this->assertCount(1, $deps);
        $this->assertSame('%apiKey%', $deps['apiKey']);
    }

    public function testResolveControllerWithNullableString(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithNullableString::class);

        $this->assertCount(1, $deps);
        $this->assertNull($deps['optionalConfig']);
    }

    public function testResolveControllerWithNullableStringWhenParameterExists(): void
    {
        $container = new MockContainer(['optionalConfig' => 'value']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithNullableString::class);

        $this->assertCount(1, $deps);
        // Nullable string with no default value still gets null, not the parameter
        $this->assertNull($deps['optionalConfig']);
    }

    public function testResolveControllerWithDefaultValue(): void
    {
        $container = new MockContainer();
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithDefault::class);

        $this->assertCount(1, $deps);
        $this->assertSame('30', $deps['timeout']);
    }

    public function testResolveControllerWithMultipleDependencies(): void
    {
        $container = new MockContainer(['apiKey' => 'secret']);
        $resolver = new ReflectionControllerArgumentResolver($container);

        $deps = $resolver->resolveArguments(ControllerWithMultipleDeps::class);

        $this->assertCount(3, $deps);
        $this->assertSame(\stdClass::class, $deps['service']);
        $this->assertSame('%apiKey%', $deps['apiKey']);
        $this->assertSame(60, $deps['timeout']); // Default value for int with default

        // Reflection still yields the parameters in signature order, so a
        // positional spread of these values would land correctly too; the
        // names make that independent of ordering.
        $this->assertSame(['service', 'apiKey', 'timeout'], array_keys($deps));
    }
}
