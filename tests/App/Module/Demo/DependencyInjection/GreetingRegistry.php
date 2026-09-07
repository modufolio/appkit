<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Tests\App\Module\Demo\DependencyInjection;

/**
 * Filled at compile time by CollectGreetingsPass from every service tagged
 * "demo.greeting", wherever it was declared — the application's
 * container.php, this module's, another module's.
 */
final class GreetingRegistry
{
    /** @var list<object> */
    public readonly array $greetings;

    public function __construct(object ...$greetings)
    {
        $this->greetings = array_values($greetings);
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_map(static fn (object $g): string => $g::class, $this->greetings);
    }
}
