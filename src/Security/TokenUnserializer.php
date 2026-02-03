<?php

namespace Modufolio\Appkit\Security;

use Modufolio\Appkit\Security\Token\TokenInterface;

final class TokenUnserializer
{
    public static function create(string $serializedToken): mixed
    {
        // Fast path — normal unserialize with class allowance
        try {
            $token = unserialize($serializedToken, ['allowed_classes' => true]);
        } catch (\Throwable) {
            // If anything goes wrong, fall back to safe mode
            $token = self::safeUnserialize($serializedToken);
        }

        // Handle unserialize failures (returns false for invalid data)
        if ($token === false) {
            return null;
        }

        // Optional type check (recommended)
        if ($token !== null && !$token instanceof TokenInterface) {
            throw new \UnexpectedValueException(sprintf(
                'Unserialized token must implement %s, got %s',
                TokenInterface::class,
                get_debug_type($token)
            ));
        }

        return $token;
    }

    private static function safeUnserialize(string $serializedToken): mixed
    {
        $prevUnserializeHandler = ini_set(
            'unserialize_callback_func',
            self::class . '::handleUnserializeCallback'
        );

        $prevErrorHandler = set_error_handler(static function ($type, $msg, $file, $line) use (&$prevErrorHandler) {
            if (__FILE__ === $file && !in_array($type, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                throw new \ErrorException($msg, 0x37313BC, $type, $file, $line);
            }
            return $prevErrorHandler ? $prevErrorHandler($type, $msg, $file, $line) : false;
        });

        try {
            return unserialize($serializedToken, ['allowed_classes' => true]);
        } catch (\ErrorException $e) {
            if (0x37313BC !== $e->getCode()) {
                throw $e;
            }
            return null;
        } finally {
            restore_error_handler();
            ini_set('unserialize_callback_func', $prevUnserializeHandler);
        }
    }

    public static function handleUnserializeCallback(string $class): void
    {
        throw new \Exception(sprintf('Class "%s" not found during unserialization.', $class));
    }
}
