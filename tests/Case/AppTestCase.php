<?php

namespace Modufolio\Appkit\Tests\Case;

use Modufolio\Appkit\Tests\App\App;
use Modufolio\Appkit\Tests\App\AppFactory;
use Modufolio\Appkit\Tests\DataFixtures\AppFixtures;
use Modufolio\Appkit\Factory\EntityFactory;
use Modufolio\Appkit\Security\User\UserInterface;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Modufolio\Appkit\Tests\Response\TestResponse;
use Modufolio\Psr7\Http\ServerRequest;
use Modufolio\Psr7\Http\Stream;
use Modufolio\Psr7\Http\Uri;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\StreamInterface;

abstract class AppTestCase extends BaseTestCase
{
    protected static App $app;

    protected function app(): App
    {
        if (!isset(self::$app)) {
            self::$app = AppFactory::create(dirname(__DIR__, 2));
            // Initialize state for tests that need it before making HTTP requests
            self::$app->initializeTestState();
        }
        return self::$app;
    }

    public function tearDown(): void
    {
        // Clear session data to ensure test isolation
        // Auth tokens, CSRF tokens, etc. must not leak between tests.
        // This preserves the PHP session mechanism (needed for RoadRunner)
        // while ensuring each test starts with a clean session.
        if ($this->app()->getState()?->hasSession()) {
            $this->app()->session()->clear();
        }

        // Clear the application instance after each test
        $this->app()->reset();
        $this->app()->configureFirewall([]);

        // Reinitialize state for the next test
        $this->app()->initializeTestState();
    }

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

        if ($platform instanceof SqlitePlatform) {
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
                    } catch (\Exception $e) {
                        // Continue even if drop fails
                    }
                }
            } catch (\Exception $e) {
                // No tables to drop
            }

            // Re-enable foreign keys
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        } else {
            // For other databases, use Doctrine's dropSchema
            $schemaTool = new SchemaTool($em);
            try {
                $schemaTool->dropSchema($metadata);
            } catch (\Exception $e) {
                // Schema might not exist yet, that's okay
            }
        }

        // Create fresh schema using the SAME EntityManager
        $schemaTool = new SchemaTool($em);
        $schemaTool->createSchema($metadata);
    }

    protected function loadFixtures(): void
    {
        $factory = (new EntityFactory(
            $this->app()->entityManager(),
            $this->app()->serializer(),
            $this->app()->validator()
        ))->loadConfig(require $this->app()->baseDir . '/config/fixture_factories.php');

        $executor = new ORMExecutor($this->app()->entityManager(), new ORMPurger());
        $executor->execute([new AppFixtures($factory)]);
    }

    // ----------------------------
    // HTTP method helpers
    // ----------------------------

    protected function get(string $uri, array $query = [], array $headers = []): TestResponse
    {
        if ($query) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->request('GET', $uri, [], null, $headers);
    }

    protected function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, null, $headers);
    }

    protected function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, null, $headers);
    }

    protected function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, null, $headers);
    }

    protected function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, null, $headers);
    }

    protected function form(string $uri, array $data = []): TestResponse
    {
        return $this->request('POST', $uri, $data, null, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    protected function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['Content-Type'] ??= 'application/json';
        return $this->request($method, $uri, $data, null, $headers);
    }

    /**
     * Create and dispatch a PSR-7 compliant request to the application.
     *
     * @throws \JsonException
     */
    protected function request(
        string $method,
        string $uri,
        array $data = [],
        ?string $body = null,
        array $headers = []
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
                $headers['Cookie'] = 'PHPSESSID=' . $sessionId;
            }
        } else {
        }

        // Add headers to server params following CGI convention
        foreach ($headers as $name => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
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

        // Set parsed body for form data and JSON
        if ($hasBody && in_array($contentType, ['application/x-www-form-urlencoded', 'application/json'], true)) {
            $request = $request->withParsedBody($data);
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
                if ($name !== null && $value !== null) {
                    $cookieParams[$name] = $value;
                }
            }
            $request = $request->withCookieParams($cookieParams);
        }

        return new TestResponse($this->app()->handle($request));
    }



    /**
     * Prepare body as PSR-7 compliant stream.
     * @throws \JsonException
     */
    private function createRequestBody(?string $contentType, array $data, ?string $raw): StreamInterface
    {
        if ($raw !== null) {
            return Stream::create($raw);
        }

        return match ($contentType) {
            'application/json' => Stream::create(json_encode($data, JSON_THROW_ON_ERROR)),
            'application/x-www-form-urlencoded' => Stream::create(http_build_query($data)),
            default => Stream::create(''), // PSR-7 compliant empty stream
        };
    }

    // ----------------------------
    // Auth helpers
    // ----------------------------

    protected function actingAs(string $email, string $password): void
    {
        // Get CSRF token for authentication
        $csrfToken = $this->app()->csrfTokenManager()->getToken('authenticate')->getValue();

        $this->form('/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $csrfToken,
        ]);

        // Assert that a token was set and the user is authenticated
        $token = $this->app()->tokenStorage()->getToken();
        $user = $token?->getUser();

        $this->assertNotNull($token, 'Expected an authentication token after login.');
        $this->assertInstanceOf(UserInterface::class, $user, 'Expected a valid User instance after login.');
    }

    protected function  login(): void
    {
        $this->actingAs('johndoe@example.com', 'secret');
    }

    protected function logout(): void
    {
        $this->get('/logout');
    }
}
