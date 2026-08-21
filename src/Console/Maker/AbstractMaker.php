<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Maker;

use Modufolio\Appkit\Console\ConsoleStyle;
use Modufolio\Appkit\Console\MakerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Convenient abstract class for makers.
 *
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
abstract class AbstractMaker implements MakerInterface
{
    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
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
