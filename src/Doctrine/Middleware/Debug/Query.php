<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine\Middleware\Debug;

use Doctrine\DBAL\ParameterType;

/**
 * @author    Filippo Tessarotto <zoeslam@gmail.com>
 *
 * @see       https://github.com/Slamdunk/dbal-debugstack-middleware
 *
 * @copyright Filippo Tessarotto
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class Query
{
    /**
     * @param array<int|string, mixed>                         $params
     * @param array<int|string, ParameterType|int|string|null> $types
     * @param string|null                                      $file   the application file that issued the query, when DebugStack collects origins
     * @param int|null                                         $line   the line in that file
     */
    public function __construct(
        public string $sql,
        public array $params,
        public array $types,
        public float $executionMs,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    public function withOrigin(string $file, int $line): self
    {
        return new self($this->sql, $this->params, $this->types, $this->executionMs, $file, $line);
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
