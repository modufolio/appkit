<?php
declare(strict_types=1);

namespace Modufolio\Appkit\Tests\Attribute;

use \Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresCommand
{
    public array $commands;
    public ?string $message;

    public function __construct(string|array $commands, ?string $message = null)
    {
        $this->commands = (array) $commands;
        $this->message = $message;
    }
}
