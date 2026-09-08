<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Exception;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Exception\ExceptionHandler;
use Modufolio\Appkit\Exception\UnresolvableServiceException;
use Modufolio\Appkit\Exception\UntrustedHostException;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorException;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ExceptionHandler::class)]
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
                'title' => 'Invalid Input',
                'detail' => $e->getMessage(),
            ];
        });

        // Handle the exception
        $response = $this->handler->handle(new \InvalidArgumentException('Invalid value'), $request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertSame('400', $body['errors'][0]['status']);
    }

    public function testRegisterAndHandleJsonException(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        // JsonException should be registered by default
        $response = $this->handler->handle(new \JsonException('Invalid JSON'), $request);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
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
                'Custom: '.($data['title'] ?? '').' - '.($data['detail'] ?? '')
            );
        });

        $response = $this->handler->handle(new \InvalidArgumentException('Test error'), $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Custom:', (string) $response->getBody());
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

    public function testUntrustedHostIsA400ThatDoesNotEchoTheHost(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(new UntrustedHostException('attacker.test'), $request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('attacker.test', (string) $response->getBody());
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

        $body = json_decode((string) $response->getBody(), true);
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

        $body = json_decode((string) $response->getBody(), true);
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

    public function testNegotiateFormatWithUnmatchedAcceptHeader(): void
    {
        // Nothing registered answers XML, so negotiation falls back to JSON:API.
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/xml; q=0.9, image/png');

        $response = $this->handler->handle(new \Exception('Test'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    public function testAWildcardAcceptStillGetsJsonApi(): void
    {
        // curl and most HTTP clients send */*; the first registered type wins,
        // which has to stay JSON:API now that text/html is registered too.
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', '*/*');

        $response = $this->handler->handle(new \Exception('Test'), $request);

        $this->assertStringContainsString('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
    }

    public function testABrowserAcceptHeaderGetsAnHtmlPage(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');

        $response = $this->handler->handle(new \RuntimeException('Disk is full'), $request);
        $body = (string) $response->getBody();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertStringContainsString('<title>500 — Runtime error</title>', $body);
        $this->assertStringContainsString('<h1>Runtime error</h1>', $body);
        $this->assertStringContainsString('<p>Disk is full</p>', $body);
    }

    public function testTheHtmlPageEscapesTitleAndDetail(): void
    {
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'text/html');

        $this->handler->registerException(\ErrorException::class, static fn () => [
            'status' => 418,
            'title' => 'Tea <b>time</b>',
            'detail' => '<script>alert("x")</script> & "quotes"',
        ]);

        $body = (string) $this->handler->handle(new \ErrorException('x'), $request)->getBody();

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<b>', $body);
        $this->assertStringContainsString('Tea &lt;b&gt;time&lt;/b&gt;', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &quot;quotes&quot;', $body);
    }

    public function testAnOwnHtmlFormatterReplacesTheBuiltInPage(): void
    {
        $this->handler->registerFormatter('text/html', static fn (array $data) => new \Modufolio\Psr7\Http\Response(
            $data['status'],
            ['Content-Type' => 'text/html'],
            '<h1>custom</h1>',
        ));

        $request = (new ServerRequest(method: 'GET', uri: '/'))->withHeader('Accept', 'text/html');
        $body = (string) $this->handler->handle(new \RuntimeException('x'), $request)->getBody();

        $this->assertSame('<h1>custom</h1>', $body);
    }

    public function testAnInertiaRequestNegotiatesToJsonApiDespiteAskingForHtml(): void
    {
        // The Inertia client sends this Accept header on its XHRs as well.
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'text/html, application/xhtml+xml')
            ->withHeader('X-Inertia', 'true');

        $response = $this->handler->handle(new \RuntimeException('Disk is full'), $request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('Disk is full', json_decode((string) $response->getBody(), true)['errors'][0]['detail']);
    }

    public function testAnUnresolvableServiceIsA500ThatNamesTheWiringInDev(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $e = new UnresolvableServiceException('App\\Service\\Mailer', $this->argumentCountError());

        $response = $this->handler->handle($e, $request);
        $error = json_decode((string) $response->getBody(), true)['errors'][0];

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Service configuration error', $error['title']);
        $this->assertStringContainsString('Service "App\\Service\\Mailer" cannot be built', $error['detail']);
        $this->assertStringContainsString('expects exactly 1 argument', $error['detail']);
    }

    public function testAnUnresolvableServiceHidesTheWiringInProdAndIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log')->with('error', $this->stringContains('cannot be built'));

        $handler = new ExceptionHandler(Environment::PROD, $logger);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        $response = $handler->handle(new UnresolvableServiceException('App\\Service\\Mailer', $this->argumentCountError()), $request);
        $error = json_decode((string) $response->getBody(), true)['errors'][0];

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('An unexpected error occurred. Please try again later.', $error['detail']);
        $this->assertStringNotContainsString('Mailer', (string) $response->getBody());
    }

    private function argumentCountError(): \ArgumentCountError
    {
        try {
            // Through reflection so the missing argument is a runtime fact,
            // not something static analysis rejects at the call site.
            (new \ReflectionClass(\DateInterval::class))->newInstance();
        } catch (\ArgumentCountError $e) {
            return $e;
        }

        throw new \LogicException('DateInterval without arguments should not construct.');
    }

    public function testErrorDetailsInDevelopment(): void
    {
        $handler = new ExceptionHandler(Environment::DEV);
        $request = (new ServerRequest(method: 'GET', uri: '/'))
            ->withHeader('Accept', 'application/json');

        $exception = new \Exception('Detailed error message');
        $response = $handler->handle($exception, $request);

        $body = json_decode((string) $response->getBody(), true);
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

        $body = json_decode((string) $response->getBody(), true);
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
        $body = json_decode((string) $response->getBody(), true);
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
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('404', $body['errors'][0]['status']);
    }

    public function testMultipleExceptionHandlers(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        // Register multiple handlers
        $this->handler->registerException(\InvalidArgumentException::class, function () {
            return [
                'status' => 400,
                'title' => 'Invalid Argument',
                'detail' => 'The argument is invalid',
            ];
        });

        $this->handler->registerException(\LogicException::class, function () {
            return [
                'status' => 500,
                'title' => 'Logic Error',
                'detail' => 'A logic error occurred',
            ];
        });

        $response1 = $this->handler->handle(new \InvalidArgumentException('test'), $request);
        $response2 = $this->handler->handle(new \LogicException('test'), $request);

        $this->assertSame(400, $response1->getStatusCode());
        $this->assertSame(500, $response2->getStatusCode());
    }

    public function testAnApplicationHandlerRegisteredAfterTheDefaultsWinsForItsSubclass(): void
    {
        // The PaymentDeclinedException walkthrough from docs/exception-handling.md,
        // verbatim: extends \RuntimeException, registered after the defaults.
        $handler = new ExceptionHandler(Environment::PROD);
        $handler->registerException(PaymentDeclinedException::class, static fn (PaymentDeclinedException $e) => [
            'status' => 402,
            'title' => 'Payment Required',
            'detail' => $e->getMessage(),
        ]);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $handler->handle(new PaymentDeclinedException('Payment was declined.'), $request);
        $error = json_decode((string) $response->getBody(), true)['errors'][0];

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('Payment Required', $error['title']);
        $this->assertSame('Payment was declined.', $error['detail']);
    }

    public function testTheMostSpecificHandlerWinsWhicheverWasRegisteredFirst(): void
    {
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        $parentFirst = new ExceptionHandler(Environment::DEV);
        $parentFirst->registerException(\RuntimeException::class, static fn () => ['status' => 500, 'title' => 'parent']);
        $parentFirst->registerException(PaymentDeclinedException::class, static fn () => ['status' => 402, 'title' => 'child']);

        $childFirst = new ExceptionHandler(Environment::DEV);
        $childFirst->registerException(PaymentDeclinedException::class, static fn () => ['status' => 402, 'title' => 'child']);
        $childFirst->registerException(\RuntimeException::class, static fn () => ['status' => 500, 'title' => 'parent']);

        foreach ([$parentFirst, $childFirst] as $handler) {
            $this->assertSame(402, $handler->handle(new PaymentDeclinedException('x'), $request)->getStatusCode());
            $this->assertSame(500, $handler->handle(new \RuntimeException('x'), $request)->getStatusCode());
        }
    }

    public function testAnInterfaceTheClassImplementsBeatsTheParentItExtends(): void
    {
        // TwoFactorException extends \RuntimeException and implements the
        // interface itself: the interface is the closer match, whichever was
        // registered first.
        $handler = new ExceptionHandler(Environment::DEV);
        $handler->registerException(\RuntimeException::class, static fn () => ['status' => 500, 'title' => 'runtime']);
        $handler->registerException(\Modufolio\Appkit\Security\TwoFactor\TwoFactorExceptionInterface::class, static fn () => ['status' => 422, 'title' => 'two-factor']);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $this->assertSame(422, $handler->handle(TwoFactorException::invalidCode(), $request)->getStatusCode());
    }

    public function testAGrandparentHandlerStillCatchesWhatNothingCloserDoes(): void
    {
        // Nothing registered for PaymentDeclinedException: its parent's
        // catch-all applies, as before.
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $response = $this->handler->handle(new PaymentDeclinedException('Payment was declined.'), $request);
        $error = json_decode((string) $response->getBody(), true)['errors'][0];

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Runtime error', $error['title']);
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

    public function testConstructorAcceptsLogger(): void
    {
        $handler = new ExceptionHandler(Environment::DEV, new NullLogger());
        $this->assertInstanceOf(ExceptionHandler::class, $handler);
    }

    public function testLoggableExceptionIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('error', 'Server broke', $this->callback(function (array $context) {
                return \RuntimeException::class === $context['exception']
                    && 500 === $context['status'];
            }));

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $handler->registerException(\RuntimeException::class, function (\RuntimeException $e) {
            return [
                'status' => 500,
                'title' => 'Runtime error',
                'detail' => $e->getMessage(),
            ];
        }, true);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \RuntimeException('Server broke'), $request);
    }

    public function testNonLoggableExceptionIsNotLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('log');
        $logger->expects($this->never())->method('error');
        $logger->expects($this->never())->method('warning');

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $handler->registerException(\InvalidArgumentException::class, function (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'title' => 'Bad Request',
                'detail' => $e->getMessage(),
            ];
        });

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \InvalidArgumentException('Bad input'), $request);
    }

    public function testLoggable4xxExceptionLoggedAsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('warning', $this->anything(), $this->anything());

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $handler->registerException(\InvalidArgumentException::class, function (\InvalidArgumentException $e) {
            return [
                'status' => 403,
                'title' => 'Forbidden',
                'detail' => $e->getMessage(),
            ];
        }, true);

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \InvalidArgumentException('Not allowed'), $request);
    }

    public function testUnmatchedExceptionLoggedAsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unexpected', $this->callback(function (array $context) {
                return 500 === $context['status'];
            }));

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \Error('Unexpected'), $request);
    }

    public function testHandlerFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error');

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $handler->registerException(\InvalidArgumentException::class, function () {
            throw new \RuntimeException('Handler crashed');
        });

        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \InvalidArgumentException('Test'), $request);
    }

    public function testDefaultLogicExceptionIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('error', $this->anything(), $this->anything());

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \LogicException('Bug in code'), $request);
    }

    public function testDefaultInvalidArgumentExceptionIsNotLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('log');
        $logger->expects($this->never())->method('error');

        $handler = new ExceptionHandler(Environment::DEV, $logger);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(new \InvalidArgumentException('Bad input'), $request);
    }

    public function testTwoFactorExceptionKeepsItsMessageForTheUser(): void
    {
        // The countdown IS the message the user needs, so it survives into
        // production rather than being flattened to a generic 500.
        $handler = new ExceptionHandler(Environment::PROD);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        $response = $handler->handle(
            new TwoFactorException('Too many failed attempts. Please try again in 40 seconds.'),
            $request,
        );

        $this->assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['errors'][0];
        $this->assertSame('Two-Factor Authentication Error', $error['title']);
        $this->assertSame('Too many failed attempts. Please try again in 40 seconds.', $error['detail']);
    }

    public function testExceptionMerelyNamedLikeATwoFactorOneLeaksNothing(): void
    {
        // Trust is opt-in through TwoFactorExceptionInterface. A class that
        // only shares the name suffix falls through to the RuntimeException
        // handler, which hides its detail outside dev.
        $handler = new ExceptionHandler(Environment::PROD);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));

        $response = $handler->handle(
            new LookAlikeTwoFactorException('connection failed: pgsql://app:hunter2@db.internal'),
            $request,
        );

        $error = json_decode((string) $response->getBody(), true)['errors'][0];
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('hunter2', (string) $response->getBody());
        $this->assertSame('An unexpected error occurred. Please try again later.', $error['detail']);
    }

    public function testTwoFactorExceptionIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('log');

        $handler = new ExceptionHandler(Environment::PROD, $logger);
        $request = new ServerRequest(method: 'GET', uri: new Uri('/'));
        $handler->handle(TwoFactorException::invalidCode(), $request);
    }
}

/**
 * Shares the old suffix-matching heuristic's magic name and nothing else.
 */
final class LookAlikeTwoFactorException extends \RuntimeException
{
}

/**
 * The application exception from the docs' end-to-end example.
 */
final class PaymentDeclinedException extends \RuntimeException
{
}
