<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Maker;

use Modufolio\Appkit\Console\ConsoleStyle;
use Modufolio\Appkit\Console\MakerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Convenient abstract class for makers.
 */
abstract class AbstractMaker implements MakerInterface
{
    /**
     * @return void
     */
    public function interact(InputInterface $input, ConsoleStyle $io, Command $command)
    {
    }

    /**
     * @return void
     */
    protected function writeSuccessMessage(ConsoleStyle $io)
    {
        $io->newLine();
        $io->writeln(' <bg=green;fg=white>          </>');
        $io->writeln(' <bg=green;fg=white> Success! </>');
        $io->writeln(' <bg=green;fg=white>          </>');
        $io->newLine();
    }
}
