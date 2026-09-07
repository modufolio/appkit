<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Debug;

use Modufolio\Appkit\Debug\ProfilerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * The profiler the test app declares in config/services.php: remembers what
 * the kernel handed it and stamps the response, the way a real one would
 * name the stored profile in a header.
 */
final class RecordingProfiler implements ProfilerInterface
{
    /** @var list<array{request: ServerRequestInterface, response: ResponseInterface, events: list<string>}> */
    private array $collected = [];
    private int $resets = 0;

    public function __construct(private readonly Stopwatch $stopwatch)
    {
    }

    public function collect(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->collected[] = [
            'request' => $request,
            'response' => $response,
            'events' => array_keys($this->stopwatch->getSectionEvents(Stopwatch::ROOT)),
        ];

        return $response->withHeader('X-Profile-Id', (string) \count($this->collected));
    }

    public function reset(): void
    {
        ++$this->resets;
        $this->collected = [];
    }

    /**
     * @return list<array{request: ServerRequestInterface, response: ResponseInterface, events: list<string>}>
     */
    public function collected(): array
    {
        return $this->collected;
    }

    public function resets(): int
    {
        return $this->resets;
    }

    public function clear(): void
    {
        $this->collected = [];
    }
}
