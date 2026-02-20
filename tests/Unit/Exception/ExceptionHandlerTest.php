<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Exception;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorException;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase;

class ExceptionHandlerTest extends TestCase
{
    private ExceptionHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ExceptionHandler(Environment::DEV);
    }

    public function testConstructorWithDefaultEnvironment(): void
    {
        // Create handler without specifying environment
        $handler = new ExceptionHandler();
        $this->assertInstanceOf(ExceptionHandler::class, $handler);
    }

    public function testRegisterAndHandleCustomException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        // Register a custom exception handler
        $this->handler->registerException(\InvalidArgumentException::class, function (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'title'  => 'Invalid Input',
                'detail' => $e->getMessage(),
            ];
        });

        // Handle the exception
        $response = $this->handler->handle(new \InvalidArgumentException('Invalid value'), $request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertSame('400', $body['errors'][0]['status']);
    }

    public function testRegisterAndHandleJsonException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        // JsonException should be registered by default
        $response = $this->handler->handle(new \JsonException('Invalid JSON'), $request);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertSame('422', $body['errors'][0]['status']);
        $this->assertSame('Invalid JSON payload', $body['errors'][0]['title']);
    }

    public function testRegisterFormatter(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))->withHeader('Accept', 'text/plain');

        $this->handler->registerFormatter('text/plain', function (array $data) {
            return new \Modufolio\Psr7\Http\Response(
                $data['status'] ?? 500,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                'Custom: ' . ($data['title'] ?? '') . ' - ' . ($data['detail'] ?? '')
            );
        });

        $response = $this->handler->handle(new \InvalidArgumentException('Test error'), $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Custom:', (string)$response->getBody());
    }

    public function testHandleInvalidArgumentException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(
            new \InvalidArgumentException('Bad argument'),
            $request
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandleLogicException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(
            new \LogicException('Logic error'),
            $request
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testHandleRuntimeException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(
            new \RuntimeException('Runtime error'),
            $request
        );

        $this->assertSame(500, $response->getStatusCode());
    }



    public function testFormatWithJsonApi(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/vnd.api+json');

        $response = $this->handler->handle(new \Exception('Test'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('jsonapi', $body);
        $this->assertSame('1.0', $body['jsonapi']['version']);
    }

    public function testFormatWithJson(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/json');

        $response = $this->handler->handle(new \Exception('Test'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('status', $body);
        $this->assertSame(500, $body['status']);
    }

    public function testFormatWithPlainText(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'text/plain');

        $response = $this->handler->handle(new \Exception('Test error message'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testNegotiateFormatWithoutAcceptHeader(): void
    {
        // No Accept header, should default to JSON:API
        $request = (new ServerRequest(method: 'GET', uri: '/'));
        $response = $this->handler->handle(new \Exception('Test'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    public function testNegotiateFormatWithInvalidAcceptHeader(): void
    {
        // Invalid Accept header, should default to JSON:API
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'text/html; q=0.5, application/xhtml+xml; q=0.9');

        $response = $this->handler->handle(new \Exception('Test'), $request);

        // Falls back to JSON:API
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    public function testErrorDetailsInDevelopment(): void
    {
        $handler = new ExceptionHandler(Environment::DEV);
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/json');

        $exception = new \Exception('Detailed error message');
        $response = $handler->handle($exception, $request);

        $body = json_decode((string)$response->getBody(), true);
        // In dev, should show detailed message
        $this->assertStringContainsString('Detailed error message', $body['detail'] ?? '');
    }

    public function testErrorDetailsInProduction(): void
    {
        $handler = new ExceptionHandler(Environment::PROD);
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/json');

        $exception = new \Exception('Detailed error message');
        $response = $handler->handle($exception, $request);

        $body = json_decode((string)$response->getBody(), true);
        // In prod, should hide details
        $this->assertStringContainsString('An unexpected error occurred', $body['detail'] ?? '');
    }

    public function testHandlerExceptionFallback(): void
    {
        // Register a handler that throws an exception
        $this->handler->registerException(\InvalidArgumentException::class, function () {
            throw new \RuntimeException('Handler failed');
        });

        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/json');

        $response = $this->handler->handle(new \InvalidArgumentException('Test'), $request);

        // Should fall back to default error response
        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertArrayHasKey('status', $body);
    }

    public function testValidationFailedException(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/vnd.api+json');

        // Create a ConstraintViolationList
        $violations = new \Symfony\Component\Validator\ConstraintViolationList();

        // The handler has ValidationFailedException registered
        $response = $this->handler->handle(
            new \Symfony\Component\Validator\Exception\ValidationFailedException('test', $violations),
            $request
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testResourceNotFoundException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(
            new \Symfony\Component\Routing\Exception\ResourceNotFoundException('Not found'),
            $request
        );

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('404', $body['errors'][0]['status']);
    }

    public function testMultipleExceptionHandlers(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        // Register multiple handlers
        $this->handler->registerException(\InvalidArgumentException::class, function () {
            return [
                'status' => 400,
                'title'  => 'Invalid Argument',
                'detail' => 'The argument is invalid',
            ];
        });

        $this->handler->registerException(\LogicException::class, function () {
            return [
                'status' => 500,
                'title'  => 'Logic Error',
                'detail' => 'A logic error occurred',
            ];
        });

        $response1 = $this->handler->handle(new \InvalidArgumentException('test'), $request);
        $response2 = $this->handler->handle(new \LogicException('test'), $request);

        $this->assertSame(400, $response1->getStatusCode());
        $this->assertSame(500, $response2->getStatusCode());
    }

    public function testExceptionWithoutRegisteredHandler(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(
            new \DomainException('Not registered'),
            $request
        );

        // Should fall back to default error response
        $this->assertSame(500, $response->getStatusCode());
    }
}
