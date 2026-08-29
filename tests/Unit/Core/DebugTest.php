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

        // The handler declines deprecations (they must be logged, not thrown),
        // so PHP's default handler would print "PHP Deprecated: old api" into
        // the test output. Mute its output channels for this one call; the
        // error_reporting mask stays untouched, so the handler still takes the
        // deprecation branch rather than the @-silenced one.
        $displayErrors = ini_set('display_errors', '0');
        $errorLog = ini_set('error_log', '/dev/null');

        try {
            $result = trigger_error('old api', \E_USER_DEPRECATED);
        } finally {
            ini_set('display_errors', (string) $displayErrors);
            ini_set('error_log', (string) $errorLog);
        }

        $this->assertTrue($result);
    }
}
