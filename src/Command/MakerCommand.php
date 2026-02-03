<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Console\ConsoleStyle;
use Modufolio\Appkit\Console\FileManager;
use Modufolio\Appkit\Console\Generator;
use Modufolio\Appkit\Console\InputConfiguration;
use Modufolio\Appkit\Console\MakerInterface;
use Modufolio\Appkit\Console\Validator;
use Modufolio\Appkit\Util\TemplateLinter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MakerCommand extends Command
{
    private InputConfiguration $inputConfig;
    private ConsoleStyle $io;

    public function __construct(
        private MakerInterface $maker,
        private FileManager $fileManager,
        private Generator $generator,
        private TemplateLinter $linter,
    ) {
        $this->inputConfig = new InputConfiguration();

        parent::__construct($this->maker->getCommandName());
    }

    protected function configure(): void
    {
        $this->maker->configureCommand($this, $this->inputConfig);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new ConsoleStyle($input, $output);
        $this->fileManager->setIO($this->io);
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if (!$this->fileManager->isNamespaceConfiguredToAutoload($this->generator->getRootNamespace())) {
            $this->io->note([
                \sprintf('It looks like your app may be using a namespace other than "%s".', $this->generator->getRootNamespace()),
                'To configure this and make your life easier, see: https://symfony.com/doc/current/bundles/SymfonyMakerBundle/index.html#configuration',
            ]);
        }

        foreach ($this->getDefinition()->getArguments() as $argument) {
            if ($input->getArgument($argument->getName())) {
                continue;
            }

            if (\in_array($argument->getName(), $this->inputConfig->getNonInteractiveArguments(), true)) {
                continue;
            }

            $value = $this->io->ask($argument->getDescription(), $argument->getDefault(), Validator::notBlank(...));
            $input->setArgument($argument->getName(), $value);
        }

        $this->maker->interact($input, $this->io, $this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($output->isVerbose()) {
            $this->linter->writeLinterMessage($output);
        }

        $this->maker->generate($input, $this->io, $this->generator);

        // sanity check for custom makers
        if ($this->generator->hasPendingOperations()) {
            throw new \LogicException('Make sure to call the writeChanges() method on the generator.');
        }

        $this->linter->lintFiles($this->generator->getGeneratedFiles());

        return 0;
    }
}
