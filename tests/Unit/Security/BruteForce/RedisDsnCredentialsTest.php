<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\BruteForce;

use Modufolio\Appkit\Security\BruteForce\RedisBruteForceProtection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DSN credential parsing for RedisBruteForceProtection::fromDsn(), which
 * needs neither the extension nor a server.
 */
#[CoversClass(RedisBruteForceProtection::class)]
final class RedisDsnCredentialsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function dsns(): iterable
    {
        yield 'no credentials' => ['redis://localhost:6379/0', null, null];
        // The documented form: parse_url() reads a lone component as "user".
        yield 'password only' => ['redis://s3cret@localhost:6379/1', null, 's3cret'];
        yield 'acl user and password' => ['redis://app:s3cret@localhost:6379', 'app', 's3cret'];
        yield 'url-encoded password' => ['redis://p%40ss%3Aw%25rd@localhost', null, 'p@ss:w%rd'];
        yield 'unix socket' => ['redis:///var/run/redis.sock', null, null];
    }

    #[DataProvider('dsns')]
    public function testCredentialsFromDsn(string $dsn, ?string $user, ?string $password): void
    {
        $this->assertSame([$user, $password], RedisBruteForceProtection::credentialsFromDsn(RedisBruteForceProtection::parseDsn($dsn)));
    }

    /**
     * parse_url() returns false for an empty authority, so the documented
     * socket DSN needs its own path through parseDsn().
     */
    public function testSocketDsnParsesToABarePath(): void
    {
        $parsed = RedisBruteForceProtection::parseDsn('redis:///var/run/redis.sock');

        $this->assertSame('/var/run/redis.sock', $parsed['path'] ?? null);
        $this->assertArrayNotHasKey('host', $parsed);
    }

    public function testUnreadableDsnIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        RedisBruteForceProtection::parseDsn('redis://:@:');
    }
}
