<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Logger that captures all log entries in memory for test assertions.
 */
class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasRecord(string $level, string $messageSubstring): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $messageSubstring)) {
                return true;
            }
        }
        return false;
    }

    public function hasWarning(string $messageSubstring): bool
    {
        return $this->hasRecord('warning', $messageSubstring);
    }

    public function hasInfo(string $messageSubstring): bool
    {
        return $this->hasRecord('info', $messageSubstring);
    }

    public function hasError(string $messageSubstring): bool
    {
        return $this->hasRecord('error', $messageSubstring);
    }

    public function countRecords(string $level): int
    {
        return count(array_filter($this->records, fn(array $r) => $r['level'] === $level));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
