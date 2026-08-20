<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console;

final class InputConfiguration
{
    /** @var list<string> */
    private array $nonInteractiveArguments = [];

    /**
     * Call in MakerInterface::configureCommand() to disable the automatic interactive
     * prompt for an argument.
     */
    public function setArgumentAsNonInteractive(string $argumentName): void
    {
        $this->nonInteractiveArguments[] = $argumentName;
    }

    /**
     * @return list<string>
     */
    public function getNonInteractiveArguments(): array
    {
        return $this->nonInteractiveArguments;
    }
}
