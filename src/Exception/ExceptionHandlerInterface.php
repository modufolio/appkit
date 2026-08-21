<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for Exception Handling.
 *
 * Provides contract for handling exceptions and formatting error responses
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
interface ExceptionHandlerInterface
{
    /**
     * Register an exception handler for a specific exception class.
     *
     * @template T of \Throwable
     *
     * @param class-string<T>                                           $exceptionClass
     * @param callable(T, ServerRequestInterface): array<string, mixed> $handler
     * @param bool                                                      $loggable       Whether this exception should be logged
     */
    public function registerException(string $exceptionClass, callable $handler, bool $loggable = false): void;

    /**
     * Register a response formatter for a specific MIME type.
     *
     * @param callable(array<string, mixed>): ResponseInterface $formatter
     */
    public function registerFormatter(string $mimeType, callable $formatter): void;

    /**
     * Handle an exception and return a formatted response.
     *
     * @throws \Throwable If exception cannot be handled
     */
    public function handle(\Throwable $e, ServerRequestInterface $request): ResponseInterface;
}
