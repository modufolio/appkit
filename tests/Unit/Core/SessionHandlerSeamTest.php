<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\SessionConfiguration;
use Modufolio\Appkit\DependencyInjection\ServiceConfigurator;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;

/**
 * Where session data lives and how the cookie is issued are declared in
 * config/services.php; the per-request state is built from both.
 *
 * Runs in its own process: PHP registers a userland session handler only
 * while nothing has been output yet, and PHPUnit's progress output in the
 * shared process counts. A worker runtime never outputs before a session
 * starts, so this is a test-harness constraint, not a runtime one.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionHandlerSeamTest extends AppTestCase
{
    /** @var \ArrayObject<string, string> */
    private \ArrayObject $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshDatabase();
        $this->loadFixtures();
        $this->store = new \ArrayObject();
    }

    /**
     * The app is shared across tests; put the defaults back so later tests
     * store sessions in files again.
     */
    public function tearDown(): void
    {
        $services = new ServiceConfigurator();
        $services
            ->set(\SessionHandlerInterface::class, fn () => new NativeFileSessionHandler($this->app()->varDir().'/sessions'))
            ->set(SessionConfiguration::class, fn () => SessionConfiguration::fromEnvironment());
        $this->app()->configureServices($services);

        parent::tearDown();
    }

    /**
     * Declare, then drop the state primed by the test case so the next request
     * builds one from the declaration, as a real process does at boot.
     */
    private function declare(ServiceConfigurator $services): void
    {
        $this->app()->configureServices($services);
        $this->app()->reset();
        $this->app()->initializeConsoleState();
    }

    private function arrayHandler(): \SessionHandlerInterface
    {
        return new class($this->store) implements \SessionHandlerInterface {
            /** @param \ArrayObject<string, string> $store */
            public function __construct(private \ArrayObject $store)
            {
            }

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string|false
            {
                return $this->store[$id] ?? '';
            }

            public function write(string $id, string $data): bool
            {
                $this->store[$id] = $data;

                return true;
            }

            public function destroy(string $id): bool
            {
                unset($this->store[$id]);

                return true;
            }

            public function gc(int $max_lifetime): int|false
            {
                return 0;
            }
        };
    }

    public function testADeclaredHandlerStoresTheSessionAndServesTheNextRequest(): void
    {
        $services = new ServiceConfigurator();
        $services->set(\SessionHandlerInterface::class, fn () => $this->arrayHandler());
        $this->declare($services);

        $this->actingAs('johndoe@example.com', 'secret');

        $this->assertGreaterThan(0, $this->store->count(), 'the login wrote the session through the declared handler');

        $this->get('/');

        $this->assertSame('johndoe@example.com', $this->app()->tokenStorage()->getToken()?->getUser()?->getUserIdentifier());
    }

    public function testTheHandlerIsBuiltOncePerProcess(): void
    {
        $built = 0;
        $services = new ServiceConfigurator();
        $services->set(\SessionHandlerInterface::class, function () use (&$built) {
            ++$built;

            return $this->arrayHandler();
        });
        $this->declare($services);

        $this->get('/');
        $this->get('/');

        $this->assertSame(1, $built);
    }

    public function testADeclaredConfigurationNamesTheCookie(): void
    {
        $services = new ServiceConfigurator();
        $services->set(SessionConfiguration::class, fn () => new SessionConfiguration(name: 'APPSESSID'));
        $this->declare($services);

        $this->get('/');

        $this->assertSame('APPSESSID', $this->app()->getState()?->getSessionCookieName());
    }
}
