<?php

namespace Modufolio\Appkit\Tests\Unit\Security;

use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\TokenInterface;
use Modufolio\Appkit\Security\TokenUnserializer;
use Modufolio\Appkit\Security\User\InMemoryUser;
use PHPUnit\Framework\TestCase;

class TokenUnserializerTest extends TestCase
{
    private InMemoryUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new InMemoryUser('test@example.com', 'password', ['ROLE_USER']);
    }

    public function testCreateSuccessfullyUnserializesValidToken(): void
    {
        $token = new JwtToken($this->user, 'main', ['aud' => 'test'], ['ROLE_USER']);
        $serialized = serialize($token);

        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertEquals('test@example.com', $result->getUserIdentifier());
        $this->assertEquals(['ROLE_USER'], $result->getRoleNames());
    }

    public function testCreateHandlesDifferentTokenTypes(): void
    {
        $token = new RememberMeToken($this->user, 'main', 'secret-key');
        $serialized = serialize($token);

        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(RememberMeToken::class, $result);
        $this->assertEquals('test@example.com', $result->getUserIdentifier());
    }

    public function testCreateReturnsNullForInvalidSerialization(): void
    {
        $serialized = 'O:99:"NonExistentClass":0:{}';

        $result = TokenUnserializer::create($serialized);

        $this->assertNull($result);
    }

    public function testCreateThrowsExceptionForNonTokenInterfaceObject(): void
    {
        $nonToken = new \stdClass();
        $serialized = serialize($nonToken);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unserialized token must implement');
        $this->expectExceptionMessage(TokenInterface::class);

        TokenUnserializer::create($serialized);
    }

    public function testCreateAllowsNullResult(): void
    {
        // Serialization that results in null should be allowed
        $serialized = serialize(null);

        $result = TokenUnserializer::create($serialized);

        $this->assertNull($result);
    }

    public function testCreateUsesTypeCheckWithGetDebugType(): void
    {
        $array = ['key' => 'value'];
        $serialized = serialize($array);

        try {
            TokenUnserializer::create($serialized);
            $this->fail('Expected UnexpectedValueException to be thrown');
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString('array', $e->getMessage());
        }
    }

    public function testCreateHandlesMalformedSerializedData(): void
    {
        $malformed = 'this is not valid serialized data';

        $result = TokenUnserializer::create($malformed);

        $this->assertNull($result);
    }

    public function testCreateHandlesCorruptedSerializedData(): void
    {
        // Create a valid serialization then corrupt it
        $token = new JwtToken($this->user, 'main');
        $serialized = serialize($token);
        $corrupted = substr($serialized, 0, -10) . 'corrupted';

        $result = TokenUnserializer::create($corrupted);

        $this->assertNull($result);
    }

    public function testSafeUnserializeFallbackWorksWhenFastPathFails(): void
    {
        // Create a serialization that will trigger fallback
        $serialized = 'O:99:"NonExistentTokenClass":0:{}';

        $result = TokenUnserializer::create($serialized);

        // Should return null due to safe mode handling
        $this->assertNull($result);
    }

    public function testCreatePreservesTokenAttributes(): void
    {
        $token = new JwtToken($this->user, 'main', ['custom' => 'data']);
        $token->setAttribute('session_id', '123456');
        $token->setAttribute('ip_address', '192.168.1.1');

        $serialized = serialize($token);
        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertTrue($result->hasAttribute('session_id'));
        $this->assertEquals('123456', $result->getAttribute('session_id'));
        $this->assertTrue($result->hasAttribute('ip_address'));
        $this->assertEquals('192.168.1.1', $result->getAttribute('ip_address'));
    }

    public function testCreatePreservesTokenRoles(): void
    {
        $roles = ['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];
        $token = new JwtToken($this->user, 'main', [], $roles);

        $serialized = serialize($token);
        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertEquals($roles, $result->getRoleNames());
    }

    public function testCreateHandlesEmptySerializedString(): void
    {
        $empty = '';

        $result = TokenUnserializer::create($empty);

        $this->assertNull($result);
    }

    public function testCreateHandlesTokenWithComplexUserObject(): void
    {
        $roles = ['ROLE_USER', 'ROLE_PREMIUM', 'ROLE_VERIFIED'];
        $complexUser = new InMemoryUser(
            'complex@example.com',
            'complex-password',
            $roles,
            true
        );

        $token = new JwtToken($complexUser, 'api', ['aud' => 'api', 'scope' => 'read:write'], $roles);

        $serialized = serialize($token);
        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertEquals('complex@example.com', $result->getUserIdentifier());
        $this->assertEquals($roles, $result->getRoleNames());
    }

    public function testHandleUnserializeCallbackThrowsExceptionWithClassName(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Class "NonExistentClass" not found during unserialization.');

        TokenUnserializer::handleUnserializeCallback('NonExistentClass');
    }

    public function testCreateWithAllowedClassesTrueInOptions(): void
    {
        // Verify that allowed_classes => true is used
        // This test ensures tokens with internal classes can be unserialized
        $token = new JwtToken($this->user, 'main');
        $serialized = serialize($token);

        $result = TokenUnserializer::create($serialized);

        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertNotNull($result->getUser());
    }

    public function testCreateFastPathPerformance(): void
    {
        $token = new JwtToken($this->user, 'main');
        $serialized = serialize($token);

        $start = microtime(true);
        $result = TokenUnserializer::create($serialized);
        $duration = microtime(true) - $start;

        // Fast path should complete quickly (under 0.1 seconds for normal cases)
        $this->assertInstanceOf(JwtToken::class, $result);
        $this->assertLessThan(0.1, $duration, 'Fast path should complete quickly');
    }
}
