<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Core;

use Modufolio\Appkit\Core\SessionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionConfiguration::class)]
final class SessionConfigurationTest extends TestCase
{
    public function testDefaultsAreTheHardenedOnes(): void
    {
        $options = (new SessionConfiguration())->toStorageOptions();

        $this->assertSame('PHPSESSID', $options['name']);
        $this->assertFalse($options['cookie_secure']);
        $this->assertTrue($options['cookie_httponly']);
        $this->assertSame('Lax', $options['cookie_samesite']);
        $this->assertSame(1, $options['use_strict_mode']);
        $this->assertSame(0, $options['cookie_lifetime']);
        $this->assertArrayNotHasKey('cookie_domain', $options);
        $this->assertArrayNotHasKey('gc_maxlifetime', $options);
    }

    public function testEveryOptionReachesTheStorage(): void
    {
        $options = (new SessionConfiguration(
            name: 'APPSESSID',
            cookieSecure: true,
            cookieSameSite: 'Strict',
            cookiePath: '/panel',
            cookieDomain: '.example.com',
            cookieLifetime: 3600,
            gcMaxLifetime: 7200,
        ))->toStorageOptions();

        $this->assertSame('APPSESSID', $options['name']);
        $this->assertTrue($options['cookie_secure']);
        $this->assertSame('Strict', $options['cookie_samesite']);
        $this->assertSame('/panel', $options['cookie_path']);
        $this->assertSame('.example.com', $options['cookie_domain']);
        $this->assertSame(3600, $options['cookie_lifetime']);
        $this->assertSame(7200, $options['gc_maxlifetime']);
    }

    public function testSameSiteNoneRequiresSecure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionConfiguration(cookieSameSite: 'None');
    }

    public function testUnknownSameSiteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionConfiguration(cookieSameSite: 'lax');
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SessionConfiguration(name: '');
    }
}
