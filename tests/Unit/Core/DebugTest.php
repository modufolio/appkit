<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\Debug;
use PHPUnit\Framework\TestCase;

class DebugTest extends TestCase
{
    private int $previousErrorReporting;

    protected function setUp(): void
    {
        $this->previousErrorReporting = error_reporting();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        error_reporting($this->previousErrorReporting);
    }

    public function testWarningsAreThrownAsErrorException(): void
    {
        Debug::enable();

        try {
            trigger_error('boom', \E_USER_WARNING);
            $this->fail('Expected an ErrorException.');
        } catch (\ErrorException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertSame(\E_USER_WARNING, $e->getSeverity());
        }
    }

    public function testNoticesAreThrownAsErrorException(): void
    {
        Debug::enable();

        $this->expectException(\ErrorException::class);

        trigger_error('notice', \E_USER_NOTICE);
    }

    public function testSilencedErrorsAreNotThrown(): void
    {
        Debug::enable();

        $result = @trigger_error('silenced', \E_USER_WARNING);

        $this->assertTrue($result);
    }

    public function testDeprecationsAreNotThrown(): void
    {
        Debug::enable();

        $result = trigger_error('old api', \E_USER_DEPRECATED);

        $this->assertTrue($result);
    }
}
