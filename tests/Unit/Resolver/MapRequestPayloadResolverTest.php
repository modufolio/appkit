<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Resolver;

use Modufolio\Appkit\Attributes\MapRequestPayload;
use Modufolio\Appkit\Attributes\MapQueryString;
use Modufolio\Appkit\Form\ValidationResult;
use Modufolio\Appkit\Resolver\AssociativeArrayResolver;
use Modufolio\Appkit\Resolver\AttributeParameterResolver;
use Modufolio\Appkit\Resolver\MapRequestPayloadResolver;
use Modufolio\Appkit\Resolver\ResolverPipeline;
use Modufolio\Appkit\Resolver\TypeHintContainerResolver;
use Modufolio\Appkit\Resolver\TypeHintResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class MapRequestPayloadResolverTest extends TestCase
{
    private ValidatorInterface $validator;
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer(
            [new ObjectNormalizer(), new ArrayDenormalizer()],
            [new JsonEncoder()]
        );

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testThrowOnErrorTrueThrowsValidationException(): void
    {
        $request = $this->createRequest(['name' => '', 'email' => 'invalid']);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'storeWithThrow'
        );

        $this->expectException(ValidationFailedException::class);

        $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);
    }

    public function testThrowOnErrorFalseWithInvalidDataInjectsValidationResult(): void
    {
        $request = $this->createRequest(['name' => '', 'email' => 'invalid']);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'storeWithValidationResult'
        );

        $result = $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);

        $this->assertArrayHasKey('dto', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertInstanceOf(TestCreateUserDto::class, $result['dto']);
        $this->assertInstanceOf(ValidationResult::class, $result['result']);
        $this->assertTrue($result['result']->failed());
        $this->assertNotEmpty($result['result']->errors());
    }

    public function testThrowOnErrorFalseWithValidDataInjectsNull(): void
    {
        $request = $this->createRequest(['name' => 'John', 'email' => 'john@example.com']);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'storeWithValidationResult'
        );

        $result = $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);

        $this->assertArrayHasKey('dto', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertInstanceOf(TestCreateUserDto::class, $result['dto']);
        $this->assertNull($result['result']);
        $this->assertSame('John', $result['dto']->name);
        $this->assertSame('john@example.com', $result['dto']->email);
    }

    public function testThrowOnErrorTrueWithValidDataReturnsDto(): void
    {
        $request = $this->createRequest(['name' => 'Jane', 'email' => 'jane@example.com']);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'storeWithThrow'
        );

        $result = $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);

        $this->assertArrayHasKey('dto', $result);
        $this->assertInstanceOf(TestCreateUserDto::class, $result['dto']);
        $this->assertSame('Jane', $result['dto']->name);
        $this->assertSame('jane@example.com', $result['dto']->email);
    }

    public function testQueryStringThrowOnErrorFalseInjectsValidationResult(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['name' => '', 'email' => 'bad']);
        $request->method('getParsedBody')->willReturn([]);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'listWithValidationResult'
        );

        $result = $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertInstanceOf(TestCreateUserDto::class, $result['query']);
        $this->assertInstanceOf(ValidationResult::class, $result['result']);
        $this->assertTrue($result['result']->failed());
    }

    public function testValidationResultStaysNullWhenNoAttribute(): void
    {
        $request = $this->createRequest(['name' => 'John', 'email' => 'john@example.com']);

        $pipeline = $this->createPipeline($request);

        $reflection = new \ReflectionMethod(
            TestPayloadController::class,
            'storeDefault'
        );

        $result = $pipeline->getParameters($reflection, [
            ServerRequestInterface::class => $request,
        ], []);

        $this->assertArrayHasKey('dto', $result);
        $this->assertInstanceOf(TestCreateUserDto::class, $result['dto']);
        // ValidationResult should NOT be in resolved params since throwOnError defaults to true
        $this->assertArrayNotHasKey('result', $result);
    }

    private function createRequest(array $parsedBody): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($parsedBody);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    private function createPipeline(ServerRequestInterface $request): ResolverPipeline
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        return (new ResolverPipeline())
            ->addResolver(new AssociativeArrayResolver())
            ->addResolver(new TypeHintResolver())
            ->addResolver(new AttributeParameterResolver([
                new MapRequestPayloadResolver(
                    $this->serializer,
                    $request,
                    $this->validator
                )
            ]))
            ->addResolver(new TypeHintContainerResolver($container));
    }
}
