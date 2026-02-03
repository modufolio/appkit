<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Exception;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Psr7\Http\Response;
use Negotiation\BaseAccept;
use Negotiation\Negotiator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

final class ExceptionHandler
{
    /** @var array<class-string<\Throwable>, callable(\Throwable, ServerRequestInterface): array> */
    private array $handlers = [];

    /** @var array<string, callable(array): ResponseInterface> */
    private array $formatters = [];

    private Negotiator $negotiator;
    private Environment $environment;

    public function __construct(?Environment $environment = null)
    {
        $this->negotiator = new Negotiator();
        $this->environment = $environment ?? Environment::from(env('APP_ENV', 'prod'));

        $this->registerDefaultFormatters();
        $this->registerDefaultExceptions();
    }

    public function registerException(string $exceptionClass, callable $handler): void
    {
        $this->handlers[$exceptionClass] = $handler;
    }

    public function registerFormatter(string $mimeType, callable $formatter): void
    {
        $this->formatters[$mimeType] = $formatter;
    }

    public function handle(\Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $data = null;

        foreach ($this->handlers as $class => $handler) {
            if ($e instanceof $class) {
                $data = $handler($e, $request);
                break;
            }
        }

        if ($data === null) {
            $data = $this->defaultData($e);
        }

        $mimeType = $this->negotiateFormat($request);

        return $this->format($data, $mimeType);
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

    private function format(array $data, string $mimeType): ResponseInterface
    {
        if (!isset($this->formatters[$mimeType])) {
            $mimeType = 'application/vnd.api+json';
        }

        return $this->formatters[$mimeType]($data);
    }

    private function defaultData(\Throwable $e): array
    {
        // Only show detailed error messages in development/test environments
        // In production, hide internal error details to prevent information disclosure
        $detail = $this->shouldShowDetails()
            ? $e->getMessage()
            : 'An unexpected error occurred. Please try again later.';

        return [
            'status' => 500,
            'title'  => 'Internal Server Error',
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
        $this->registerFormatter('application/vnd.api+json', function (array $data) {
            $status = $data['status'] ?? 500;

            $errors = $data['errors'] ?? [[
                'status' => (string)$status,
                'title'  => $data['title'] ?? 'Error',
                'detail' => $data['detail'] ?? null,
            ]];

            return new Response(
                $status,
                ['Content-Type' => 'application/vnd.api+json'],
                json_encode([
                    'jsonapi' => ['version' => '1.0'],
                    'errors'  => $errors,
                ], JSON_THROW_ON_ERROR)
            );
        });

        // JSON
        $this->registerFormatter('application/json', function (array $data) {
            return new Response(
                $data['status'] ?? 500,
                ['Content-Type' => 'application/json'],
                json_encode($data, JSON_THROW_ON_ERROR)
            );
        });

        // Plain text
        $this->registerFormatter('text/plain', function (array $data) {
            $status = $data['status'] ?? 500;
            $title  = $data['title'] ?? 'Error';
            $detail = $data['detail'] ?? '';

            return new Response(
                $status,
                ['Content-Type' => 'text/plain'],
                $title . ($detail ? ': ' . $detail : '')
            );
        });
    }

    private function registerDefaultExceptions(): void
    {
        // Invalid input
        $this->registerException(\InvalidArgumentException::class, function (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'title'  => 'Bad Request',
                'detail' => $e->getMessage(),
            ];
        });

        // JSON decoding errors
        $this->registerException(\JsonException::class, function (\JsonException $e) {
            return [
                'status' => 422,
                'title'  => 'Invalid JSON payload',
                'detail' => $e->getMessage(),
            ];
        });

        $this->registerException(PayloadTooLargeException::class, function (PayloadTooLargeException $e) {
            return [
                'status' => 413,
                'title'  => 'Payload Too Large',
                'detail' => $e->getMessage(),
            ];
        });


        // Fallback for any LogicException (developer errors)
        // Hide details in production as these are internal logic errors
        $this->registerException(\LogicException::class, function (\LogicException $e) {
            $detail = $this->shouldShowDetails()
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again later.';

            return [
                'status' => 500,
                'title'  => 'Logic error',
                'detail' => $detail,
            ];
        });

        // Resource not found (404 errors)
        $this->registerException(ResourceNotFoundException::class, function (ResourceNotFoundException $e) {
            return [
                'status' => 404,
                'title'  => 'Resource not found',
                'detail' => $e->getMessage(),
            ];
        });

        // Validation errors
        $this->registerException(\Symfony\Component\Validator\Exception\ValidationFailedException::class, function (\Symfony\Component\Validator\Exception\ValidationFailedException $e) {
            $violations = $e->getViolations();
            $errors = [];

            /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
            foreach ($violations as $violation) {
                $errors[] = [
                    'status' => '422',
                    'title'  => 'Validation error',
                    'detail' => $violation->getMessage(),
                    'source' => [
                        'pointer' => '/data/attributes/' . $violation->getPropertyPath(),
                    ],
                ];
            }

            return [
                'status' => 422,
                'errors' => $errors,
            ];
        });

        // Authentication errors
        $this->registerException(\Appkit\Security\Exception\AuthenticationException::class, function (\Appkit\Security\Exception\AuthenticationException $e) {
            return [
                'status' => 401,
                'title'  => 'Authentication failed',
                'detail' => $e->getMessage(),
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
                'title'  => 'Runtime error',
                'detail' => $detail,
            ];
        });
    }
}
