<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Http;

use Modufolio\Appkit\Exception\UntrustedHostException;
use Modufolio\Appkit\Http\TrustedHosts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrustedHosts::class)]
final class TrustedHostsTest extends TestCase
{
    public function testEmptyListAllowsAnyHost(): void
    {
        $hosts = new TrustedHosts();

        $this->assertTrue($hosts->isEmpty());
        $this->assertTrue($hosts->allows('anything.example'));
        $this->assertTrue($hosts->allows(''));
    }

    public function testExactHostIsComparedCaseInsensitively(): void
    {
        $hosts = new TrustedHosts(['Example.COM']);

        $this->assertTrue($hosts->allows('example.com'));
        $this->assertTrue($hosts->allows('EXAMPLE.com'));
        $this->assertFalse($hosts->allows('www.example.com'));
        $this->assertFalse($hosts->allows('example.com.attacker.test'));
        $this->assertFalse($hosts->allows('attacker.test'));
    }

    public function testWildcardMatchesSubdomainsButNotTheApex(): void
    {
        $hosts = new TrustedHosts(['*.example.com']);

        $this->assertTrue($hosts->allows('www.example.com'));
        $this->assertTrue($hosts->allows('a.b.example.com'));
        $this->assertFalse($hosts->allows('example.com'), 'the apex must be listed separately');
        $this->assertFalse($hosts->allows('notexample.com'));
        $this->assertFalse($hosts->allows('example.com.attacker.test'));
    }

    public function testEmptyHostIsAcceptedBecauseItYieldsPathOnlyUrls(): void
    {
        $hosts = new TrustedHosts(['example.com']);

        $this->assertTrue($hosts->allows(''));
    }

    public function testAssertThrowsForAnUnlistedHost(): void
    {
        $hosts = new TrustedHosts(['example.com']);

        $hosts->assert('example.com');

        try {
            $hosts->assert('attacker.test');
            $this->fail('Expected UntrustedHostException');
        } catch (UntrustedHostException $e) {
            $this->assertSame('attacker.test', $e->getHost());
            $this->assertStringContainsString('attacker.test', $e->getMessage());
        }
    }

    public function testSymfonyRegexPatternsAreAcceptedAsIs(): void
    {
        // Straight from a Symfony framework.trusted_hosts entry.
        $hosts = new TrustedHosts(['^(.+\\.)?example\\.com$', '^localhost$']);

        $this->assertTrue($hosts->allows('example.com'));
        $this->assertTrue($hosts->allows('WWW.example.com'));
        $this->assertTrue($hosts->allows('localhost'));
        $this->assertFalse($hosts->allows('example.com.attacker.test'));
        $this->assertFalse($hosts->allows('attacker.test'));
        $this->assertSame(['^(.+\\.)?example\\.com$', '^localhost$'], $hosts->toSymfonyPatterns());
    }

    public function testRegexPatternsAreUnanchoredLikeSymfony(): void
    {
        $hosts = new TrustedHosts(['example\\.com']);

        $this->assertTrue($hosts->allows('example.com'));
        $this->assertTrue($hosts->allows('example.com.attacker.test'), 'unanchored, exactly as Symfony treats it');
    }

    public function testToSymfonyPatternsRendersShorthandsAsAnchoredRegexes(): void
    {
        $hosts = new TrustedHosts(['Example.com', '*.example.com', '^api\\.example\\.com$']);

        $this->assertSame(
            ['^example\\.com$', '^.+\\.example\\.com$', '^api\\.example\\.com$'],
            $hosts->toSymfonyPatterns(),
        );

        // The rendered list is itself a valid configuration with the same meaning.
        $roundTrip = new TrustedHosts($hosts->toSymfonyPatterns());
        foreach (['example.com', 'www.example.com', 'api.example.com'] as $host) {
            $this->assertTrue($roundTrip->allows($host), $host);
        }
        $this->assertFalse($roundTrip->allows('example.com.attacker.test'));
    }

    public function testHostIsNormalisedLikeSymfonyRequestGetHost(): void
    {
        $hosts = new TrustedHosts(['example.com']);

        $this->assertTrue($hosts->allows(' Example.com:8443 '));
    }

    public function testSyntacticallyInvalidHostsAreRejectedEvenWithoutAList(): void
    {
        $this->assertFalse((new TrustedHosts())->allows('evil host'));
        $this->assertFalse((new TrustedHosts())->allows('exa<mple.com'));
        $this->assertFalse((new TrustedHosts())->allows('1.2.3.999'));
        $this->assertTrue((new TrustedHosts())->allows('127.0.0.1'));
        $this->assertTrue((new TrustedHosts())->allows('[::1]'));
        $this->assertTrue((new TrustedHosts())->allows('my_host-1.internal'));
    }

    public function testIpv6LiteralIsAccepted(): void
    {
        $hosts = new TrustedHosts(['[::1]']);

        $this->assertTrue($hosts->allows('[::1]'));
        $this->assertFalse($hosts->allows('localhost'));
    }

    public function testToArrayNormalisesEntries(): void
    {
        $hosts = new TrustedHosts([' Example.com ', '*.Example.com']);

        $this->assertSame(['example.com', '*.example.com'], $hosts->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'not a string' => [42];
        yield 'empty' => [''];
        yield 'url' => ['https://example.com'];
        yield 'host with port' => ['example.com:8080'];
        yield 'inner wildcard' => ['www.*.example.com'];
        yield 'double wildcard' => ['*.*.example.com'];
        yield 'bare wildcard' => ['*.'];
        yield 'broken regex' => ['^(example\\.com$'];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesAreRejectedAtConstruction(mixed $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrustedHosts([$entry]);
    }
}
