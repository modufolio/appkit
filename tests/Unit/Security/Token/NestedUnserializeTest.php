<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Token;

use Modufolio\Appkit\Security\Token\ApiKeyToken;
use Modufolio\Appkit\Security\Token\JwtToken;
use Modufolio\Appkit\Security\Token\RememberMeToken;
use Modufolio\Appkit\Security\Token\SwitchUserToken;
use Modufolio\Appkit\Security\Token\TwoFactorToken;
use Modufolio\Appkit\Security\Token\UsernamePasswordToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A class that is NOT on TokenUnserializer's allowlist, standing in for any
 * autoloadable class with side effects in __wakeup/__destruct.
 */
class NotAllowlistedGadget
{
    public static bool $constructed = false;

    public function __wakeup(): void
    {
        self::$constructed = true;
    }
}

/**
 * Security regression test: `allowed_classes` is applied PER unserialize()
 * call and does not propagate into nested ones.
 *
 * The token classes used to end __unserialize() with
 *
 *     $parentData = is_array($parentData) ? $parentData : unserialize($parentData);
 *
 * so a forged payload carrying a *string* in the parent slot reached that
 * second call with the default `allowed_classes: true` — reconstructing any
 * autoloadable class and defeating the allowlist TokenUnserializer exists to
 * enforce. __serialize() always writes an array there, so the fallback only
 * ever served forged input; it is now rejected.
 */
class NestedUnserializeTest extends TestCase
{
    protected function setUp(): void
    {
        NotAllowlistedGadget::$constructed = false;
    }

    /**
     * Forge a payload for $class whose parent slot (the last one) is a STRING
     * holding a serialized gadget, with benign strings in the slots before it.
     *
     * @param class-string $class
     */
    private static function forgePayload(string $class, int $size): string
    {
        $data = array_fill(0, $size - 1, 'benign');
        $data[$size - 1] = serialize([new NotAllowlistedGadget(), true, null, [], []]);

        $inner = substr(serialize($data), strlen('a:'.$size.':'));

        return 'O:'.strlen($class).':"'.$class.'":'.$size.':'.$inner;
    }

    /**
     * @return iterable<string, array{class-string, int}>
     */
    public static function provideTokens(): iterable
    {
        yield UsernamePasswordToken::class => [UsernamePasswordToken::class, 3];
        yield ApiKeyToken::class => [ApiKeyToken::class, 4];
        yield JwtToken::class => [JwtToken::class, 4];
        yield RememberMeToken::class => [RememberMeToken::class, 4];
        yield TwoFactorToken::class => [TwoFactorToken::class, 3];
        yield SwitchUserToken::class => [SwitchUserToken::class, 3];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideTokens')]
    public function testStringInParentSlotIsRejectedAndNoGadgetIsConstructed(string $class, int $size): void
    {
        $payload = self::forgePayload($class, $size);

        try {
            // Even with the token itself allowlisted — exactly what
            // TokenUnserializer does — the nested payload must not be decoded.
            unserialize($payload, ['allowed_classes' => [$class]]);
            $this->fail('Expected a rejection for a string parent slot in '.$class);
        } catch (\BadMethodCallException) {
            // The explicit guard rejected it.
        } catch (\TypeError) {
            // Some tokens have typed non-string slots before the parent one
            // (JwtToken::$payload, SwitchUserToken::$originalToken), so a
            // forged payload trips the property type first. Also a rejection,
            // and it happens before the parent slot is ever read.
        }

        // The invariant that matters, whichever rejection fired first.
        $this->assertFalse(
            NotAllowlistedGadget::$constructed,
            'A class outside the allowlist must never be constructed'
        );
    }
}
