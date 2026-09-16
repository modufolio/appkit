<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Event;

use Modufolio\Appkit\Event\Security\UserLoggedInEvent;
use Modufolio\Appkit\Event\Security\UserLoggedOutEvent;
use Modufolio\Appkit\Tests\App\TestLogger;
use Modufolio\Appkit\Tests\Case\AppTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * notify() is the seam's two guarantees in one method: a failing listener
 * changes nothing, and a kernel event cannot be dispatched from inside
 * another's listener.
 */
class NotifyGuardTest extends AppTestCase
{
    private TestLogger $logger;
    private EventDispatcherInterface $previous;
    private \ReflectionProperty $loggerProperty;
    private mixed $previousLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new TestLogger();
        $this->previous = $this->app()->eventDispatcher();

        $this->loggerProperty = new \ReflectionProperty($this->app(), 'logger');
        $this->previousLogger = $this->loggerProperty->getValue($this->app());
        $this->loggerProperty->setValue($this->app(), $this->logger);
    }

    public function tearDown(): void
    {
        $this->app()->setEventDispatcher($this->previous);
        $this->loggerProperty->setValue($this->app(), $this->previousLogger);

        parent::tearDown();
    }

    private function loggedIn(): UserLoggedInEvent
    {
        return new UserLoggedInEvent('a@example.com', 'main', 'form_login', ['ROLE_USER'], false, null);
    }

    public function testANestedKernelEventIsRefusedAndLogged(): void
    {
        $app = $this->app();
        $seen = [];

        $app->setEventDispatcher(new class($app, $seen) implements EventDispatcherInterface {
            /** @param list<class-string> $seen */
            public function __construct(private readonly \Modufolio\Appkit\Tests\App\App $app, public array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = $event::class;

                // A listener that "logs the user out again": the cycle the
                // guard exists to break.
                if ($event instanceof UserLoggedInEvent) {
                    $this->app->notify(new UserLoggedOutEvent('a@example.com', 'main', null));
                }

                return $event;
            }
        });

        $app->notify($this->loggedIn());

        $this->assertSame([UserLoggedInEvent::class], $seen, 'The nested event never reached the dispatcher');
        $this->assertTrue($this->logger->hasError('dispatched from inside a listener'));
    }

    public function testTheGuardIsReleasedAfterwards(): void
    {
        $app = $this->app();
        $count = 0;

        $app->setEventDispatcher(new class($count) implements EventDispatcherInterface {
            public function __construct(public int &$count)
            {
            }

            public function dispatch(object $event): object
            {
                ++$this->count;

                return $event;
            }
        });

        $app->notify($this->loggedIn());
        $app->notify(new UserLoggedOutEvent('a@example.com', 'main', null));

        $this->assertSame(2, $count, 'Sequential notifications are not nested');
        $this->assertSame(0, $this->logger->countRecords('error'));
    }

    public function testTheGuardIsReleasedWhenTheListenerThrows(): void
    {
        $app = $this->app();
        $count = 0;

        $app->setEventDispatcher(new class($count) implements EventDispatcherInterface {
            public function __construct(public int &$count)
            {
            }

            public function dispatch(object $event): object
            {
                ++$this->count;

                throw new \RuntimeException('listener broke');
            }
        });

        $app->notify($this->loggedIn());
        $this->assertTrue($this->logger->hasError('An event listener failed'));

        $app->notify($this->loggedIn());
        $this->assertSame(2, $count, 'A failure inside one notification does not jam the next');
    }
}
