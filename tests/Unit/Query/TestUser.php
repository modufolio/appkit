<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Unit\Query;

/**
 * Query-resolution fixture shared by the Segment, Segments, Expression and
 * Query test cases.
 *
 * It lives in its own file so those test cases can be run individually (and in
 * parallel), rather than relying on another test file having been loaded first.
 */
class TestUser
{
    public function username(): string
    {
        return 'homer';
    }

    /**
     * @return array<string, string>
     */
    public function profiles(): array
    {
        return [
            'mastodon' => '@homer',
        ];
    }

    public function says(string ...$message): string
    {
        return implode(' : ', $message);
    }

    public function age(int $years): int
    {
        return $years;
    }

    public function isYello(bool $answer): bool
    {
        return $answer;
    }

    public function brainDump(mixed $dump): mixed
    {
        return $dump;
    }

    /**
     * @return array{args: array<int|string, mixed>}
     */
    public function array(mixed ...$args): array
    {
        return ['args' => $args];
    }

    /**
     * @param list<mixed> $array
     */
    public function check(mixed $needle1, mixed $needle2, array $array): bool
    {
        return in_array($needle1, $array) && in_array($needle2, $array);
    }

    /**
     * @return list<string>
     */
    public function drink(): array
    {
        return ['gin', 'tonic', 'cucumber'];
    }

    public function self(): self
    {
        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function likes(array $arguments): self
    {
        foreach ($arguments as $arg) {
            if (false === in_array($arg, ['(', ')', ',', ']', '['])) {
                throw new \Exception();
            }
        }

        return $this;
    }

    public function nothing(): null
    {
        return null;
    }
}
