<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\InsecureChannelException;
use Modufolio\Psr7\Http\Response;
use Negotiation\BaseAccept;
use Negotiation\Negotiator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * Each entry only ever receives the exception class it is keyed by, which is
     * narrower than \Throwable — a per-key relationship the type system cannot
     * express, so the stored signature stays unconstrained.
     *
     * @var array<class-string<\Throwable>, callable>
     */
    private array $handlers = [];

    /** @var array<string, callable(array<string, mixed>): ResponseInterface> */
    private array $formatters = [];

    /** @var array<class-string<\Throwable>, bool> */
    private array $loggable = [];

    private Negotiator $negotiator;
    private Environment $environment;
    private LoggerInterface $logger;

    public function __construct(?Environment $environment = null, ?LoggerInterface $logger = null)
    {
        $this->negotiator = new Negotiator();
        $this->environment = $environment ?? Environment::from(env('APP_ENV', 'prod'));
        $this->logger = $logger ?? new NullLogger();

        $this->registerDefaultFormatters();
        $this->registerDefaultExceptions();
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T>                                           $exceptionClass
     * @param callable(T, ServerRequestInterface): array<string, mixed> $handler
     */
    public function registerException(string $exceptionClass, callable $handler, bool $loggable = false): void
    {
        $this->handlers[$exceptionClass] = $handler;
        $this->loggable[$exceptionClass] = $loggable;
    }

    /**
     * @param callable(array<string, mixed>): ResponseInterface $formatter
     */
    public function registerFormatter(string $mimeType, callable $formatter): void
    {
        $this->formatters[$mimeType] = $formatter;
    }

    public function handle(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        // A required-channel violation is a redirect to the https URL, not an
        // error payload — issue it before the error-formatting machinery runs.
        if ($e instanceof InsecureChannelException) {
            return Response::redirect($e->getTargetUrl(), 301);
        }

        $data = null;
        $matchedClass = null;

        try {
            // Try to handle with registered exception handlers
            foreach ($this->handlers as $class => $handler) {
                if (!$e instanceof $class) {
                    continue;
                }

                $data = $handler($e, $request);
                $matchedClass = $class;
                break;
            }

            // Handle 2FA exceptions using the interface
            if (null === $data) {
                $data = $this->handleTwoFactorException($e);
            }

            if (null === $data) {
                $data = $this->defaultData($e);
            }
        } catch (\Throwable $handlerException) {
            // If handler fails, fall back to default error response
            $this->logger->error('Exception handler failed', [
                'handler_exception' => $handlerException->getMessage(),
                'original_exception' => $e->getMessage(),
                'original_exception_class' => $e::class,
            ]);
            $data = $this->defaultData($handlerException);
        }

        $this->logException($e, $data, $matchedClass);

        $mimeType = $this->negotiateFormat($request);

        return $this->format($data, $mimeType);
    }

    /**
     * Handle TwoFactorException using the exception handler interface.
     *
     * @return array<string, mixed>|null
     */
    private function handleTwoFactorException(\Throwable $e): ?array
    {
        // Use reflection to check if this is a 2FA exception
        // This avoids tight coupling to the concrete exception class
        $exceptionClassName = $e::class;

        // Check if the exception class name ends with TwoFactorException
        if (str_ends_with($exceptionClassName, 'TwoFactorException')) {
            return [
                'status' => 422,
                'title' => 'Two-Factor Authentication Error',
                'detail' => $e->getMessage(),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function logException(\Throwable $e, array $data, ?string $matchedClass): void
    {
        $status = $data['status'] ?? 500;
        $context = [
            'exception' => $e::class,
            'status' => $status,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        // Unmatched exceptions that default to 5xx are always logged as errors
        if (null === $matchedClass && $status >= 500) {
            $this->logger->error($e->getMessage(), $context);

            return;
        }

        if (null === $matchedClass) {
            return;
        }

        $level = $this->resolveLogLevel($matchedClass, $status);

        if (null === $level) {
            return;
        }

        $this->logger->log($level, $e->getMessage(), $context);
    }

    private function resolveLogLevel(string $exceptionClass, int $status): ?string
    {
        if (!($this->loggable[$exceptionClass] ?? false)) {
            return null;
        }

        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };
    }

    private function negotiateFormat(ServerRequestInterface $request): string
    {
        $accept = $request->getHeaderLine('Accept');
        $priorities = array_keys($this->formatters);

        if (empty($accept)) {
            return 'application/vnd.api+json'; // default to JSON:API
        }

        $best = $this->negotiator->getBest($accept, $priorities);

        return $best instanceof BaseAccept ? $best->getValue() : 'application/vnd.api+json'; // default to JSON:API
    }

    /**
     * @param array<string, mixed> $data
     */
    private function format(array $data, string $mimeType): ResponseInterface
    {
        if (!isset($this->formatters[$mimeType])) {
            $mimeType = 'application/vnd.api+json';
        }

        return $this->formatters[$mimeType]($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultData(\Throwable $e): array
    {
        // Only show detailed error messages in development/test environments
        // In production, hide internal error details to prevent information disclosure
        $detail = $this->shouldShowDetails()
            ? $e->getMessage()
            : 'An unexpected error occurred. Please try again later.';

        return [
            'status' => 500,
            'title' => 'Internal Server Error',
            'detail' => $detail,
        ];
    }

    /**
     * Determine if detailed error messages should be shown.
     * Only show in development and test environments.
     */
    private function shouldShowDetails(): bool
    {
        return $this->environment->isDev() || $this->environment->isTest();
    }

    private function registerDefaultFormatters(): void
    {
        // JSON:API
        $this->registerFormatter('application/vnd.api+json', static function (array $data) {
            $status = $data['status'] ?? 500;

            $errors = $data['errors'] ?? [[
                'status' => (string) $status,
                'title' => $data['title'] ?? 'Error',
                'detail' => $data['detail'] ?? null,
            ]];

            return new Response(
                $status,
                ['Content-Type' => 'application/vnd.api+json'],
                json_encode([
                    'jsonapi' => ['version' => '1.0'],
                    'errors' => $errors,
                ], JSON_THROW_ON_ERROR)
            );
        });

        // JSON
        $this->registerFormatter('application/json', static function (array $data) {
            return new Response(
                $data['status'] ?? 500,
                ['Content-Type' => 'application/json'],
                json_encode($data, JSON_THROW_ON_ERROR)
            );
        });

        // Plain text
        $this->registerFormatter('text/plain', static function (array $data) {
            $status = $data['status'] ?? 500;
            $title = $data['title'] ?? 'Error';
            $detail = $data['detail'] ?? '';

            return new Response(
                $status,
                ['Content-Type' => 'text/plain'],
                $title.($detail ? ': '.$detail : '')
            );
        });
    }

    private function registerDefaultExceptions(): void
    {
        // Invalid input
        $this->registerException(\InvalidArgumentException::class, static function (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'title' => 'Bad Request',
                'detail' => $e->getMessage(),
            ];
        });

        // JSON decoding errors
        $this->registerException(\JsonException::class, static function (\JsonException $e) {
            return [
                'status' => 422,
                'title' => 'Invalid JSON payload',
                'detail' => $e->getMessage(),
            ];
        });

        $this->registerException(PayloadTooLargeException::class, static function (PayloadTooLargeException $e) {
            return [
                'status' => 413,
                'title' => 'Payload Too Large',
                'detail' => $e->getMessage(),
            ];
        });

        // Host header not on the trusted-hosts allowlist. Registered ahead of
        // the \RuntimeException catch-all so it maps to 400, not 500. The
        // rejected host is deliberately not echoed — it is attacker input and
        // is available in the log entry instead.
        $this->registerException(UntrustedHostException::class, static function (UntrustedHostException $e) {
            return [
                'status' => 400,
                'title' => 'Bad Request',
                'detail' => 'The request host is not allowed.',
            ];
        }, true);

        // Fallback for any LogicException (developer errors)
        // Hide details in production as these are internal logic errors
        $this->registerException(\LogicException::class, function (\LogicException $e) {
            $detail = $this->shouldShowDetails()
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again later.';

            return [
                'status' => 500,
                'title' => 'Logic error',
                'detail' => $detail,
            ];
        }, true);

        // Resource not found (404 errors)
        $this->registerException(ResourceNotFoundException::class, static function (ResourceNotFoundException $e) {
            return [
                'status' => 404,
                'title' => 'Resource not found',
                'detail' => $e->getMessage(),
            ];
        });

        // Method not allowed (405 errors)
        // Note: $e->getMessage() already includes the allowed methods.
        $this->registerException(MethodNotAllowedException::class, static function (MethodNotAllowedException $e) {
            return [
                'status' => 405,
                'title' => 'Method not allowed',
                'detail' => $e->getMessage(),
            ];
        });

        // Validation errors
        $this->registerException(ValidationFailedException::class, static function (ValidationFailedException $e) {
            $violations = $e->getViolations();
            $errors = [];

            /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
            foreach ($violations as $violation) {
                $errors[] = [
                    'status' => '422',
                    'title' => 'Validation error',
                    'detail' => $violation->getMessage(),
                    'source' => [
                        'pointer' => '/data/attributes/'.$violation->getPropertyPath(),
                    ],
                ];
            }

            return [
                'status' => 422,
                'errors' => $errors,
            ];
        });

        // Authentication errors
        //
        // The exception message is intentionally NOT echoed. Authentication
        // exceptions carry detail that helps debugging (e.g. "JWT signature
        // invalid", "Account is locked", "Insufficient roles for path /admin")
        // — but those are reconnaissance signals for an attacker. Detail stays
        // in the logger; the client gets a generic 401.
        //
        // Authenticators that need a richer response (e.g. WWW-Authenticate
        // challenge headers) should return one from unauthorizedResponse()
        // before the exception bubbles to this handler.
        $this->registerException(AuthenticationException::class, static function (AuthenticationException $e) {
            return [
                'status' => 401,
                'title' => 'Authentication failed',
                'detail' => 'Authentication required.',
            ];
        });

        // Authenticated but not allowed — distinct from 401 (not authenticated).
        $this->registerException(AccessDeniedException::class, static function (AccessDeniedException $e) {
            return [
                'status' => 403,
                'title' => 'Access denied',
                'detail' => 'You do not have permission to access this resource.',
            ];
        });

        // Runtime errors
        // Hide details in production as these are internal runtime errors
        $this->registerException(\RuntimeException::class, function (\RuntimeException $e) {
            $detail = $this->shouldShowDetails()
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again later.';

            return [
                'status' => 500,
                'title' => 'Runtime error',
                'detail' => $detail,
            ];
        }, true);
    }
}
