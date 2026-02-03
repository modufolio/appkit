<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Command;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('serve');
        $this->setDescription('Serve the application on the PHP development server');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkPhpVersion();

        $output->writeln('PHP development server started on http://localhost:8000');

        $command = escapeshellarg(PHP_BINARY) . ' -S localhost:8000 -t public router.php';

        passthru($command);

        return Command::SUCCESS;
    }

    /**
     * Check the current PHP version is >= 8.0.
     *
     * @return void
     *
     * @throws Exception
     */
    protected function checkPhpVersion(): void
    {
        if (PHP_VERSION_ID < 80000) {
            throw new \RuntimeException('This PHP binary is not version 8.0 or greater.');
        }
    }
}
