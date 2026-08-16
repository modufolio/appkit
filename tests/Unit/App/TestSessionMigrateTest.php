<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\App;

use Modufolio\Appkit\Tests\App\TestSession;
use PHPUnit\Framework\TestCase;

/**
 * Locks the TestSession double to the real SessionInterface::migrate contract.
 *
 * A prior version wiped the current attributes when $destroy was true — the
 * inverse of real semantics — which would have masked a session-fixation bug
 * (it made migrate(false) look mandatory to keep the auth token). migrate()
 * always carries attributes over; $destroy only controls deleting the OLD
 * storage.
 */
class TestSessionMigrateTest extends TestCase
{
    public function testMigratePreservesAttributesRegardlessOfDestroy(): void
    {
        foreach ([false, true] as $destroy) {
            $session = new TestSession();
            $session->set('_security_main', 'token');

            $session->migrate($destroy);

            $this->assertSame(
                'token',
                $session->get('_security_main'),
                'attributes must survive migrate($destroy: '.var_export($destroy, true).')',
            );
        }
    }

    public function testMigrateAlwaysIssuesANewId(): void
    {
        $session = new TestSession();
        $session->start();
        $before = $session->getId();

        $session->migrate(true);

        $this->assertNotSame($before, $session->getId());
    }

    public function testDestroyTrueRetiresTheOldId(): void
    {
        $session = new TestSession();
        $session->start();
        $before = $session->getId();

        $session->migrate(true);

        $this->assertContains($before, $session->getDestroyedIds());
    }

    public function testDestroyFalseDoesNotRetireTheOldId(): void
    {
        $session = new TestSession();
        $session->start();
        $before = $session->getId();

        $session->migrate(false);

        $this->assertNotContains($before, $session->getDestroyedIds());
    }
}
