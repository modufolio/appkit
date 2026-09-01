<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Testing;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Modufolio\Appkit\Core\Kernel;
use Modufolio\Appkit\Security\User\UserInterface;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\StreamInterface;

/**
 * Feature-test base for AppKit applications.
 *
 * Subclasses supply the one thing the framework cannot know — their app —
 * through {@see app()}; everything else ships: in-process request dispatch
 * with SAPI-faithful server params, session and CSRF continuity across
 * requests, engine-agnostic database refreshing, and auth helpers against
 * the framework's form-login conventions.
 *
 * ```php
 * abstract class AppTestCase extends \Modufolio\Appkit\Testing\AppTestCase
 * {
 *     private static ?App $app = null;
 *
 *     protected function app(): App
 *     {
 *         if (null === self::$app) {
 *             self::$app = AppFactory::create(dirname(__DIR__), 'test');
 *             self::$app->initializeConsoleState();
 *         }
 *
 *         return self::$app;
 *     }
 * }
 * ```
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
abstract class AppTestCase extends BaseTestCase
{
    /**
     * When true, the next request skips the automatic X-CSRF-Token header,
     * letting tests exercise the firewall's CSRF rejection path.
     */
    private bool $skipAutoCsrf = false;

    /**
     * The application under test — the only seam a consumer must fill.
     * Return the same instance for the whole process (the harness resets it
     * between tests), primed with initializeConsoleState() on first build so
     * the container is usable before the first dispatched request. Declaring
     * your concrete App as the return type gives every test typed access.
     */
    abstract protected function app(): Kernel;

    /**
     * Hook: seed the database. No-op by default; applications with Doctrine
     * fixtures override it.
     */
    protected function loadFixtures(): void
    {
    }

    public function tearDown(): void
    {
        // Clear session data to ensure test isolation
        // Auth tokens, CSRF tokens, etc. must not leak between tests.
        // This preserves the PHP session mechanism (needed for worker
        // runtimes) while ensuring each test starts with a clean session.
        if ($this->app()->getState()?->hasSession()) {
            $this->app()->session()->clear();
        }

        // A test that failed mid-flush can leave the shared connection inside
        // an aborted transaction, which would poison every test after it.
        $connection = $this->app()->entityManager()->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // Clear the application instance after each test
        $this->app()->reset();
        $this->resetAppConfiguration();

        // Reinitialize state for the next test
        $this->app()->initializeConsoleState();
    }

    /**
     * Hook: undo per-test configuration changes after reset(), before the
     * next test's state is primed. No-op by default — an app whose tests
     * reconfigure firewalls (the framework's own do) restores them here.
     */
    protected function resetAppConfiguration(): void
    {
    }

    /**
     * @throws DbalException
     */
    protected function refreshDatabase(): void
    {
        // Get EntityManager and metadata WITHOUT closing/resetting
        // This is crucial for SQLite to maintain the same database connection
        $em = $this->app()->entityManager();
        $connection = $em->getConnection();
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        if (!$metadata) {
            throw new \RuntimeException('No metadata found — check your entities.');
        }

        // For SQLite, manually drop all tables with foreign key constraints disabled
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            // Disable foreign keys for SQLite
            $connection->executeStatement('PRAGMA foreign_keys = OFF');

            // Get list of all tables
            $schemaManager = $connection->createSchemaManager();
            try {
                $tables = $schemaManager->listTableNames();

                // Drop each table
                foreach ($tables as $table) {
                    try {
                        $connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
                    } catch (DbalException $e) {
                        // Continue even if drop fails
                    }
                }
            } catch (DbalException $e) {
                // No tables to drop
            }

            // Re-enable foreign keys
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        } else {
            // A real engine keeps tables across runs — including unmapped
            // ones left by DBAL-level tests, whose foreign keys block
            // dropping the mapped tables. SchemaTool::dropSchema() swallows
            // those failures, so the collision would only surface on the next
            // createSchema(). Drop everything visible instead, with
            // referential checks suspended where the platform supports it and
            // multiple passes where it does not.
            $schemaManager = $connection->createSchemaManager();
            $mysql = $platform instanceof AbstractMySQLPlatform;

            if ($mysql) {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }

            try {
                do {
                    $remaining = $schemaManager->listTableNames();
                    $dropped = 0;
                    foreach ($remaining as $table) {
                        try {
                            $connection->executeStatement(
                                $platform instanceof PostgreSQLPlatform
                                    ? sprintf('DROP TABLE IF EXISTS %s CASCADE', $table)
                                    : sprintf('DROP TABLE IF EXISTS %s', $table)
                            );
                            ++$dropped;
                        } catch (DbalException) {
                            // Still referenced by a table later in the list —
                            // the next pass gets it once the referrer is gone.
                        }
                    }
                } while ([] !== $remaining && $dropped > 0);
            } finally {
                if ($mysql) {
                    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
        }

        // Create fresh schema using the SAME EntityManager
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($metadata);

        $this->afterSchemaCreate();
    }

    /**
     * Hook: run after refreshDatabase() rebuilt the mapped schema. SchemaTool
     * builds tables from entity metadata only — it knows nothing about
     * triggers or views. An app whose migrations install those applies the
     * same DDL here, so the test database enforces the exact invariants
     * production does instead of silently diverging.
     */
    protected function afterSchemaCreate(): void
    {
    }

    // ----------------------------
    // HTTP method helpers
    // ----------------------------

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     *
     * @throws \JsonException
     */
    protected function get(string $uri, array $query = [], array $headers = []): TestResponse
    {
        if ($query) {
            $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->request('GET', $uri, [], null, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, null, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, null, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, null, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, null, $headers);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function form(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, null, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            ...$headers,
        ]);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['Content-Type'] ??= 'application/json';

        return $this->request($method, $uri, $data, null, $headers);
    }

    /**
     * Create and dispatch a PSR-7 compliant request to the application.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     *
     * @throws \JsonException
     */
    protected function request(
        string $method,
        string $uri,
        array $data = [],
        ?string $body = null,
        array $headers = [],
    ): TestResponse {
        $method = strtoupper($method);
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $contentType = $headers['Content-Type'] ?? null;

        // Create PSR-7 compliant URI object
        $uriObject = new Uri($uri);

        // Create request body stream
        $stream = $this->createRequestBody($contentType, $data, $body);

        // Create base server parameters
        $serverParams = [
            'HTTP_HOST' => 'localhost',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SCRIPT_NAME' => '',
            'QUERY_STRING' => $uriObject->getQuery(),
        ];

        // Always add default Accept if none provided
        if (!isset($headers['Accept'])) {
            $headers['Accept'] = '*/*';
        }

        // Include session cookie if session is active
        // This ensures CSRF tokens and other session data persist across test requests
        if ($this->app()->getState() && $this->app()->getState()->hasSession()) {
            $sessionId = $this->app()->session()->getId();
            if ($sessionId) {
                $headers['Cookie'] = 'PHPSESSID='.$sessionId;
            }
        }

        // Mirror a real browser/XHR client: attach a CSRF token on session-backed,
        // state-changing requests so the firewall's CSRF guard (enforced on the
        // restored-session, remember-me and anonymous public-path branches) is
        // satisfied. A browser rendering a form on a public page embeds the
        // token the same way, so this covers anonymous sessions too. Tests that
        // supply their own token, or run without a session, are left untouched.
        $state = $this->app()->getState();
        $hasLiveSession = $state && $state->hasSession();
        $alreadyHasCsrf = isset($headers['X-CSRF-Token'])
            || isset($headers['X-XSRF-Token'])
            || array_key_exists('_csrf_token', $data);

        if ($this->skipAutoCsrf) {
            $this->skipAutoCsrf = false;
        } elseif ($hasBody && $hasLiveSession && !$alreadyHasCsrf) {
            $headers['X-CSRF-Token'] = $this->app()
                ->csrfTokenManager()
                ->getToken('csrf')
                ->getValue();
        }

        // Add headers to server params following CGI convention. Content-Type
        // and Content-Length are exposed WITHOUT the HTTP_ prefix, exactly as
        // a real SAPI does.
        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $serverKey = in_array($normalized, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
                ? $normalized
                : 'HTTP_'.$normalized;
            $serverParams[$serverKey] = $value;
        }

        // Create PSR-7 ServerRequest without headers in constructor
        $request = new ServerRequest(
            method: $method,
            uri: $uriObject,
            headers: [],
            body: $stream,
            version: '1.1',
            serverParams: $serverParams
        );

        // Add headers using PSR-7 withHeader method
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Populate the parsed body the way production does: ServerRequestCreator
        // decodes php://input by content type. Decoding the stream we actually
        // wrote (instead of reusing $data) keeps the encode/decode round-trip
        // under test.
        if ($hasBody) {
            $contents = (string) $stream;
            if ($stream->isSeekable()) {
                $stream->rewind(); // leave the stream readable for handlers using getContents()
            }
            $parsed = $this->parseBodyLikeSapi($contentType, $contents);
            if (null !== $parsed) {
                $request = $request->withParsedBody($parsed);
            }
        }

        // Set query parameters
        if ($uriObject->getQuery()) {
            parse_str($uriObject->getQuery(), $queryParams);
            $request = $request->withQueryParams($queryParams);
        }

        // Parse Cookie header into cookieParams for PSR-7 compliance
        if (isset($headers['Cookie'])) {
            $cookieParams = [];
            $cookies = explode('; ', $headers['Cookie']);
            foreach ($cookies as $cookie) {
                [$name, $value] = explode('=', $cookie, 2) + [null, null];
                if (null !== $name && null !== $value) {
                    $cookieParams[$name] = $value;
                }
            }
            $request = $request->withCookieParams($cookieParams);
        }

        return new TestResponse($this->app()->handle($request));
    }

    /**
     * Skip the automatic X-CSRF-Token header for the next request only.
     */
    protected function withoutCsrfToken(): static
    {
        $this->skipAutoCsrf = true;

        return $this;
    }

    /**
     * Decode a request body by content type, mirroring ServerRequestCreator's
     * treatment of php://input in production.
     *
     * @return array<string, mixed>|null
     */
    private function parseBodyLikeSapi(?string $contentType, string $contents): ?array
    {
        switch ($contentType) {
            case 'application/json':
                $decoded = json_decode($contents, true);

                return is_array($decoded) ? $decoded : [];
            case 'application/x-www-form-urlencoded':
                $parsed = [];
                parse_str($contents, $parsed);

                $normalised = [];
                foreach ($parsed as $key => $value) {
                    $normalised[(string) $key] = $value;
                }

                return $normalised;
            default:
                return null;
        }
    }

    /**
     * Prepare body as PSR-7 compliant stream.
     *
     * @param array<string, mixed> $data
     *
     * @throws \JsonException
     */
    private function createRequestBody(?string $contentType, array $data, ?string $raw): StreamInterface
    {
        if (null !== $raw) {
            return Stream::create($raw);
        }

        // Drop any parameters (`; charset=utf-8`) and honour the RFC 6839
        // structured syntax suffix, so a media type like JSON:API's
        // application/vnd.api+json is encoded as JSON rather than silently
        // going out as an empty body — which surfaces far away as the endpoint
        // rejecting the request for invalid JSON.
        $mediaType = strtolower(trim(explode(';', $contentType ?? '')[0]));

        if ('application/json' === $mediaType || str_ends_with($mediaType, '+json')) {
            return Stream::create(json_encode($data, JSON_THROW_ON_ERROR));
        }

        if ('application/x-www-form-urlencoded' === $mediaType) {
            return Stream::create(http_build_query($data));
        }

        return Stream::create(''); // PSR-7 compliant empty stream
    }

    // ----------------------------
    // Auth helpers
    // ----------------------------

    /**
     * Log in through the framework's form-login conventions (/login with
     * email/password fields). Override in your subclass if your app routes
     * or names them differently.
     *
     * @throws \JsonException
     */
    protected function actingAs(string $email, string $password): void
    {
        // Get CSRF token for authentication
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $response = $this->form('/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $csrfToken,
        ]);

        // Surface the actual HTTP failure (422, 500, ...) before the opaque
        // token assertion below.
        $status = $response->getResponse()->getStatusCode();
        $this->assertLessThan(400, $status, sprintf(
            'Login request for "%s" failed with status %d: %s',
            $email,
            $status,
            substr((string) $response->getResponse()->getBody(), 0, 500),
        ));

        // Assert that a token was set and the user is authenticated
        $token = $this->app()->tokenStorage()->getToken();
        $user = $token?->getUser();

        $this->assertNotNull($token, 'Expected an authentication token after login.');
        $this->assertInstanceOf(UserInterface::class, $user, 'Expected a valid User instance after login.');
    }

    protected function logout(): void
    {
        $token = $this->app()
            ->csrfTokenManager()
            ->getToken('logout')
            ->getValue();

        $this->post('/logout', ['_csrf_token' => $token], [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);
    }
}
