<?php

declare(strict_types = 1);

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

        $chars = implode('', $pools);

        $password = '';

        // Make sure we have at least one character from each pool
        foreach ($pools as $pool) {
            $char = self::randomChar($pool);
            $chars = str_replace($char, '', $chars);
            $password .= $char;
        }

        // Fill the rest of the password with random characters from all pools
        while (strlen($password) < $length) {
            $char = self::randomChar($chars);
            $chars = str_replace($char, '', $chars);
            $password .= $char;
        }

        return str_shuffle($password);
    }

    /**
     * @throws \Exception
     */
    public static function randomChar(string $string): string
    {
        return $string[random_int(0, strlen($string) - 1)];
    }

}
