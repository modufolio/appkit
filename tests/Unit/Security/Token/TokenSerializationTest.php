<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Token;

use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Serialization round-trips for every token type, plus fixture-pinned
 * payloads (Fixtures/*-token.txt) that freeze the CURRENT wire format.
 *
 * The fixture tests are the contract that sessions written by an older
 * release keep working after an upgrade: if a token's __serialize shape
 * changes, these fail — that is the signal that __unserialize must stay
 * able to read the old shape (and a new fixture should be added, never
 * a replaced one).
 */
class TokenSerializationTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures';

    private function user(): InMemoryUser
    {
        return new InMemoryUser('john@example.com', 'secret', ['ROLE_USER']);
    }

    public function testUsernamePasswordTokenRoundTrip(): void
    {
        $token = new UsernamePasswordToken($this->user(), 'main', ['ROLE_USER']);

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(UsernamePasswordToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
        $this->assertSame(['ROLE_USER'], $restored->getRoleNames());
        $this->assertSame('john@example.com', $restored->getUserIdentifier());
    }

    public function testRememberMeTokenRoundTrip(): void
    {
        $token = new RememberMeToken($this->user(), 'main', 's3cr3t');

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(RememberMeToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
        $this->assertSame('john@example.com', $restored->getUserIdentifier());
    }

    public function testApiKeyTokenRoundTrip(): void
    {
        $token = new ApiKeyToken($this->user(), 'main', 'key-123', ['ROLE_API_USER']);

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(ApiKeyToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
        $this->assertSame(['ROLE_API_USER'], $restored->getRoleNames());
    }

    public function testJwtTokenRoundTrip(): void
    {
        $token = new JwtToken($this->user(), 'main', ['sub' => 'john@example.com'], ['ROLE_USER']);

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(JwtToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
    }

    public function testTwoFactorTokenRoundTrip(): void
    {
        $token = new TwoFactorToken($this->user(), 'main');

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(TwoFactorToken::class, $restored);
        $this->assertSame('main', $restored->getFirewallName());
    }

    public function testSwitchUserTokenRoundTrip(): void
    {
        $original = new UsernamePasswordToken($this->user(), 'main', ['ROLE_USER']);
        $target = new InMemoryUser('target@example.com', 'x', ['ROLE_USER']);
        $token = new SwitchUserToken($target, 'main', ['ROLE_USER'], $original);

        $restored = unserialize(serialize($token));

        $this->assertInstanceOf(SwitchUserToken::class, $restored);
        $this->assertSame('target@example.com', $restored->getUserIdentifier());
        $this->assertSame('john@example.com', $restored->getOriginalToken()->getUserIdentifier());
    }

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function provideFixtures(): iterable
    {
        yield 'username_password' => ['username_password-token.txt', UsernamePasswordToken::class, 'john@example.com'];
        yield 'remember_me' => ['remember_me-token.txt', RememberMeToken::class, 'john@example.com'];
        yield 'api_key' => ['api_key-token.txt', ApiKeyToken::class, 'john@example.com'];
        yield 'jwt' => ['jwt-token.txt', JwtToken::class, 'john@example.com'];
        yield 'two_factor' => ['two_factor-token.txt', TwoFactorToken::class, 'john@example.com'];
        yield 'switch_user' => ['switch_user-token.txt', SwitchUserToken::class, 'target@example.com'];
    }

    /**
     * @param class-string $expectedClass
     */
    #[DataProvider('provideFixtures')]
    public function testStoredFixtureStillUnserializes(string $fixture, string $expectedClass, string $identifier): void
    {
        $payload = file_get_contents(self::FIXTURES.'/'.$fixture);
        $this->assertNotFalse($payload, 'Missing fixture '.$fixture);

        // through the hardened TokenUnserializer, exactly like session restore
        $token = TokenUnserializer::create($payload);

        $this->assertInstanceOf($expectedClass, $token);
        $this->assertSame($identifier, $token->getUserIdentifier());
        $this->assertTrue(method_exists($token, 'getFirewallName'));
        $this->assertSame('main', $token->getFirewallName());
    }

    public function testTokenUnserializerRejectsUnlistedClasses(): void
    {
        // \DateTime is autoloadable but not on the allow-list; it comes back
        // as __PHP_Incomplete_Class and is rejected, never handed to callers.
        $this->expectException(\UnexpectedValueException::class);

        TokenUnserializer::create(serialize(new \DateTime()));
    }
}
