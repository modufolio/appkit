<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Security;

class SecurityHelper
{
    /**
     * @throws \Exception
     */
    public static function generatePassword(int $length = 12): string
    {
        // Make sure the length is between 8 and 64 characters
        $length = max(8, min(64, $length));

        $pools = [
            'alphaLower' => 'abcdefghijklmnopqrstuvwxyz',
            'alphaUpper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'num' => '0123456789',
            'special' => '!@#$%^&*()_-=+{}[]',
        ];

        // Full pool — characters may repeat, which maximizes entropy.
        $chars = implode('', $pools);

        $password = [];

        // Guarantee at least one character from each pool, drawn with a CSPRNG.
        foreach ($pools as $pool) {
            $password[] = self::randomChar($pool);
        }

        // Fill the rest from the full pool (repeats allowed).
        while (count($password) < $length) {
            $password[] = self::randomChar($chars);
        }

        // Shuffle the result to avoid predictable sequences
        for ($i = count($password) - 1; $i > 0; --$i) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    /**
     * @throws \Exception
     */
    public static function randomChar(string $string): string
    {
        return $string[random_int(0, strlen($string) - 1)];
    }
}
