<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Exception;

use Modufolio\Appkit\Core\Environment;
use Modufolio\Appkit\Inertia\Header;
use Modufolio\Appkit\Security\Exception\AccessDeniedException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\InsecureChannelException;
use Modufolio\Appkit\Security\TwoFactor\TwoFactorExceptionInterface;
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
     * Dispatch is most-specific-wins, not first-match: see match().
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
            $matchedClass = $this->match($e);

            if (null !== $matchedClass) {
                $data = $this->handlers[$matchedClass]($e, $request);
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
    /**
     * The registered class or interface closest to the exception's own class
     * in its inheritance chain; null when nothing registered matches.
     *
     * The defaults register \InvalidArgumentException, \LogicException and
     * \RuntimeException as catch-alls, and an application's handlers are
     * registered after them. Matched in insertion order, those three would
     * shadow every application exception that extends one of them — the
     * documented PaymentDeclinedException extends \RuntimeException, and its
     * handler never ran. So the winner is the most specific match instead:
     * the class itself, then its parent, and so on up the chain, with an
     * interface counted at the level of the class that introduces it. Two
     * matches at the same distance keep insertion order, so re-registering a
     * key replaces its handler exactly as before.
     *
     * @return class-string<\Throwable>|null
     */
    private function match(\Throwable $e): ?string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($this->handlers as $class => $handler) {
            if (!$e instanceof $class) {
                continue;
            }

            $distance = self::distance($e, $class);

            if ($distance < $bestDistance) {
                $best = $class;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * How many parents up the exception's chain $class is found: 0 for the
     * exception's own class, or the level whose class first implements $class
     * when it is an interface. Only called for a class the exception is an
     * instance of, so the walk always terminates on a match.
     *
     * @param class-string<\Throwable> $class
     */
    private static function distance(\Throwable $e, string $class): int
    {
        $level = 0;

        for ($current = $e::class; false !== $current; $current = get_parent_class($current), ++$level) {
            if ($current === $class) {
                return $level;
            }

            if (\interface_exists($class) && \is_subclass_of($current, $class)) {
                $parent = get_parent_class($current);

                if (false === $parent || !\is_subclass_of($parent, $class)) {
                    return $level;
                }
            }
        }

        return $level;
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
        // The Inertia client sends `Accept: text/html, application/xhtml+xml`
        // on its XHRs as well, so a framework can route them through ordinary
        // content negotiation. Taken literally that would hand every Inertia
        // error the HTML page meant for a hard load. An Inertia visit is a
        // JSON exchange — PrepareResponse already treats it as one — so its
        // errors keep the JSON:API body the client-side handler can read.
        if ($request->hasHeader(Header::INERTIA)) {
            return 'application/vnd.api+json';
        }

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

        // HTML — a browser's default Accept header lists text/html first, so
        // without this a hard page load that errors (an address-bar visit, an
        // old bookmark) would render the JSON:API document as a blob of text.
        // Registered last: `Accept: */*` (curl, most HTTP clients) negotiates
        // to the first registered type and still gets JSON:API.
        $this->registerFormatter('text/html', static function (array $data) {
            $status = (int) ($data['status'] ?? 500);

            return new Response(
                $status,
                ['Content-Type' => 'text/html; charset=utf-8'],
                self::renderHtml(
                    $status,
                    (string) ($data['title'] ?? 'Error'),
                    (string) ($data['detail'] ?? ''),
                )
            );
        });
    }

    /**
     * The built-in error page: one self-contained document, no assets, no
     * links — the handler cannot know where the application's home is.
     * Register your own `text/html` formatter to replace it.
     */
    private static function renderHtml(int $status, string $title, string $detail): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$status} — {$e($title)}</title>
            <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f9fafb; color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            main { max-width: 28rem; margin: 1.5rem; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06); text-align: center; }
            .status { font-size: .75rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #dc2626; }
            h1 { margin: .5rem 0 .75rem; font-size: 1.25rem; }
            p { margin: 0; color: #4b5563; font-size: .875rem; line-height: 1.5; }
            </style>
            </head>
            <body>
            <main>
            <div class="status">Error {$status}</div>
            <h1>{$e($title)}</h1>
            <p>{$e($detail)}</p>
            </main>
            </body>
            </html>
            HTML;
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

        // Host header not on the trusted-hosts allowlist. Its own entry so it
        // maps to 400, not the \RuntimeException catch-all's 500. The
        // rejected host is deliberately not echoed — it is attacker input and
        // is available in the log entry instead.
        $this->registerException(UntrustedHostException::class, static function (UntrustedHostException $e) {
            return [
                'status' => 400,
                'title' => 'Bad Request',
                'detail' => 'The request host is not allowed.',
            ];
        }, true);

        // A service factory that could not build its service — a missing
        // constructor argument in services.php or a console runner. Its own
        // entry only so the title names the wiring rather than a generic
        // logic error; the rest is the same as \LogicException, which it
        // extends: 500, detail hidden in prod, logged.
        $this->registerException(UnresolvableServiceException::class, function (UnresolvableServiceException $e) {
            $detail = $this->shouldShowDetails()
                ? $e->getMessage()
                : 'An unexpected error occurred. Please try again later.';

            return [
                'status' => 500,
                'title' => 'Service configuration error',
                'detail' => $detail,
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

        // Two-factor failures the person can act on: a wrong code, an expired
        // window, a lockout with a countdown.
        //
        // TwoFactorException extends \RuntimeException, whose catch-all is
        // registered below; the interface is the closer match, so the
        // lockout keeps its countdown — which is exactly the message the
        // user needs — instead of collapsing into a generic 500.
        //
        // The message is echoed in production, which only the interface makes
        // safe: implementing it is the exception's promise that the message
        // carries nothing an anonymous caller should not read. Matching on the
        // class name instead extended that trust to any class that happens to
        // end in "TwoFactorException", including ones carrying internal detail
        // they never intended to publish.
        $this->registerException(TwoFactorExceptionInterface::class, static function (\Throwable $e) {
            return [
                'status' => 422,
                'title' => 'Two-Factor Authentication Error',
                'detail' => $e->getMessage(),
            ];
        }, true);

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
