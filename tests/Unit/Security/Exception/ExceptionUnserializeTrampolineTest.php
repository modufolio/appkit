<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Security\Exception;

use Modufolio\Appkit\Security\Exception\AccountExpiredException;
use Modufolio\Appkit\Security\Exception\AccountStatusException;
use Modufolio\Appkit\Security\Exception\AuthenticationException;
use Modufolio\Appkit\Security\Exception\BadCredentialsException;
use Modufolio\Appkit\Security\Exception\TooManyLoginAttemptsException;
use Modufolio\Appkit\Security\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A gadget class standing in for any autoloadable class whose __toString has
 * side effects. If a forged session payload can smuggle one of these into a
 * typed slot, the property assignment becomes a "trampoline" that fires the
 * gadget — the classic unserialize POP-chain primitive.
 */
class ExceptionTrampolineGadget
{
    public static bool $fired = false;

    public function __toString(): string
    {
        self::$fired = true;

        return 'pwned';
    }
}

/**
 * Security regression test (pattern from symfony/security-core, mirroring
 * Token/UnserializeTrampolineTest): a forged serialized payload must never be
 * able to turn one of the exception hierarchy's slots into a __toString
 * trampoline. AuthenticationException::__unserialize restores, in order:
 *
 *     [token, user, code, message, file, line]
 *
 * The typed slots (token, user, file, line) reject a \Stringable object with a
 * \TypeError before any coercion; the untyped string/int slots (message, code)
 * accept the object verbatim without ever invoking __toString. Either way the
 * gadget must not fire.
 *
 * All the concrete exceptions inherit this single __unserialize, so testing a
 * representative spread of subclasses proves the whole hierarchy is covered.
 *
 * Note: appkit's UserNotFoundException does NOT declare its own `identifier`
 * property (unlike upstream Symfony); it is an empty subclass that reuses
 * AuthenticationException::__unserialize, so it has no extra string slot to
 * exploit and is covered by the same guards below.
 */
class ExceptionUnserializeTrampolineTest extends TestCase
{
    /** Slot index of the typed `string $file` property (throws \TypeError). */
    private const SLOT_FILE = 4;

    /** Slot index of the untyped `$message` property (accepts object, no coercion). */
    private const SLOT_MESSAGE = 3;

    protected function setUp(): void
    {
        ExceptionTrampolineGadget::$fired = false;
    }

    /**
     * Build a raw serialized payload for $class whose __unserialize data array
     * has an ExceptionTrampolineGadget in slot $slot and benign values elsewhere.
     *
     * The data array mirrors AuthenticationException::__serialize():
     * [token, user, code, message, file, line].
     */
    private static function forgePayload(string $class, int $slot): string
    {
        $data = [null, null, 0, 'benign message', 'benign.php', 1];
        $data[$slot] = new ExceptionTrampolineGadget();

        $size = \count($data);

        // Serialize the data array, then graft it onto the target class the way
        // PHP encodes an object with __serialize data.
        $inner = serialize($data);
        $inner = substr($inner, \strlen('a:'.$size.':'));

        return 'O:'.\strlen($class).':"'.$class.'":'.$size.':'.$inner;
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideExceptionClasses(): iterable
    {
        yield AuthenticationException::class => [AuthenticationException::class];
        yield BadCredentialsException::class => [BadCredentialsException::class];
        yield UserNotFoundException::class => [UserNotFoundException::class];
        yield AccountStatusException::class => [AccountStatusException::class];
        yield AccountExpiredException::class => [AccountExpiredException::class];
        yield TooManyLoginAttemptsException::class => [TooManyLoginAttemptsException::class];
    }

    /**
     * The typed `string $file` slot rejects a \Stringable object with a
     * \TypeError, so the gadget's __toString never runs.
     *
     * @param class-string $class
     */
    #[DataProvider('provideExceptionClasses')]
    public function testStringableInTypedFileSlotIsRejected(string $class): void
    {
        $payload = self::forgePayload($class, self::SLOT_FILE);

        try {
            unserialize($payload);
            $this->fail('Expected \TypeError for gadget in the typed $file slot of '.$class);
        } catch (\TypeError $e) {
            $this->assertStringContainsString('$file', $e->getMessage());
        }

        $this->assertFalse(
            ExceptionTrampolineGadget::$fired,
            'The gadget __toString must never fire for '.$class,
        );
    }

    /**
     * The untyped `$message` slot accepts the object verbatim: no string
     * coercion happens during unserialize, so the gadget's __toString is never
     * invoked. This is the "otherwise the gadget never fires" branch of the
     * trampoline guarantee for the one string slot that is not strictly typed.
     *
     * @param class-string $class
     */
    #[DataProvider('provideExceptionClasses')]
    public function testStringableInMessageSlotNeverFires(string $class): void
    {
        $payload = self::forgePayload($class, self::SLOT_MESSAGE);

        // Whether or not this throws, the only thing that matters for security is
        // that the gadget's __toString is not triggered during unserialize.
        try {
            unserialize($payload);
        } catch (\Throwable) {
            // A future hardening that rejects the object outright is also fine.
        }

        $this->assertFalse(
            ExceptionTrampolineGadget::$fired,
            'The gadget __toString must never fire for '.$class,
        );
    }
}
