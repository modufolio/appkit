<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for Exception Handling
 *
 * Provides contract for handling exceptions and formatting error responses
 */
interface ExceptionHandlerInterface
{
    /**
     * Register an exception handler for a specific exception class
     *
     * @param class-string<\Throwable> $exceptionClass
     * @param callable(\Throwable, ServerRequestInterface): array $handler
     */
    public function registerException(string $exceptionClass, callable $handler): void;

    /**
     * Register a response formatter for a specific MIME type
     *
     * @param callable(array): ResponseInterface $formatter
     */
    public function registerFormatter(string $mimeType, callable $formatter): void;

    /**
     * Handle an exception and return a formatted response
     *
     * @throws \Throwable If exception cannot be handled
     */
    public function handle(\Throwable $e, ServerRequestInterface $request): ResponseInterface;
}
