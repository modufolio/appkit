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
 * A gadget class standing in for any autoloadable class whose __toString has
 * side effects. If a forged session payload can smuggle one of these into a
 * typed string property, the property assignment becomes a "trampoline" that
 * fires the gadget — the classic unserialize POP-chain primitive.
 */
class TrampolineGadget
{
    public static bool $fired = false;

    public function __toString(): string
    {
        self::$fired = true;

        return 'pwned';
    }
}

/**
 * Security regression test (pattern from symfony/security-core): every typed
 * string slot in a token's __unserialize must reject \Stringable objects with
 * \BadMethodCallException BEFORE any assignment, so the gadget's __toString
 * can never run.
 */
class UnserializeTrampolineTest extends TestCase
{
    protected function setUp(): void
    {
        TrampolineGadget::$fired = false;
    }

    /**
     * Build a raw serialized payload for $class whose __unserialize data array
     * has a TrampolineGadget in slot $slot and benign strings elsewhere.
     */
    private static function forgePayload(string $class, int $size, int $slot): string
    {
        $data = [];
        for ($i = 0; $i < $size; ++$i) {
            $data[$i] = $i === $slot ? new TrampolineGadget() : 'benign';
        }

        // serialize the data array, then graft it onto the target class the
        // way PHP encodes an object with __serialize data
        $inner = serialize($data);
        $inner = substr($inner, strlen('a:'.$size.':'));

        return 'O:'.strlen($class).':"'.$class.'":'.$size.':'.$inner;
    }

    /**
     * @return iterable<string, array{class-string, int, int}>
     */
    public static function provideStringSlots(): iterable
    {
        yield UsernamePasswordToken::class.' firewallName' => [UsernamePasswordToken::class, 3, 1];
        yield ApiKeyToken::class.' firewallName' => [ApiKeyToken::class, 4, 1];
        yield ApiKeyToken::class.' apiKey' => [ApiKeyToken::class, 4, 2];
        yield RememberMeToken::class.' firewallName' => [RememberMeToken::class, 4, 1];
        yield RememberMeToken::class.' secret' => [RememberMeToken::class, 4, 2];
        yield JwtToken::class.' firewallName' => [JwtToken::class, 4, 1];
        yield TwoFactorToken::class.' firewallName' => [TwoFactorToken::class, 3, 0];
        yield TwoFactorToken::class.' createdAt' => [TwoFactorToken::class, 3, 1];
        yield SwitchUserToken::class.' firewallName' => [SwitchUserToken::class, 3, 0];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideStringSlots')]
    public function testStringableInStringSlotIsRejected(string $class, int $size, int $slot): void
    {
        $payload = self::forgePayload($class, $size, $slot);

        try {
            unserialize($payload);
            $this->fail('Expected \BadMethodCallException for gadget in slot '.$slot.' of '.$class);
        } catch (\BadMethodCallException $e) {
            $this->assertSame('Cannot unserialize '.$class, $e->getMessage());
        }

        $this->assertFalse(TrampolineGadget::$fired, 'The gadget __toString must never fire');
    }
}
