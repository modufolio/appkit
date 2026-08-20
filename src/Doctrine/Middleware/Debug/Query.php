<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\ParameterType;

final readonly class Query
{
    /**
     * @param array<int|string, mixed>                $params
     * @param array<int|string, ParameterType|int|string|null> $types
     */
    public function __construct(
        public string $sql,
        public array $params,
        public array $types,
        public float $executionMs,
    ) {
    }

    public static function start(): float
    {
        return \microtime(true);
    }

    public static function end(float $start): float
    {
        return \microtime(true) - $start;
    }
}
