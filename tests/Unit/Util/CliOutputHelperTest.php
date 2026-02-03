<?php

namespace Modufolio\Appkit\Tests\Unit\Util;

use Modufolio\Appkit\Util\CliOutputHelper;
use PHPUnit\Framework\TestCase;

/**
 * @author Jesse Rushlow <jr@rushlow.dev>
 */
class CliOutputHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SYMFONY_CLI_BINARY_NAME');
        putenv('SYMFONY_CLI_VERSION');
    }

    public function testCorrectCommandPrefixReturnedWhenUsingSymfonyBinary(): void
    {
        self::assertSame('php bin/console', CliOutputHelper::getCommandPrefix());

        putenv('SYMFONY_CLI_BINARY_NAME=symfony');
        putenv('SYMFONY_CLI_VERSION=0.0.0');

        self::assertSame('symfony console', CliOutputHelper::getCommandPrefix());
    }
}
