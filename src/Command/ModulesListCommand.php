<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Module\ModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Print the module manifest as the registry sees it: names, classes,
 * declared dependencies, config keys and what each module contributes.
 * Loading the manifest also validates it, so a broken config/modules.php
 * fails here with the same aggregate error boot would give.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[AsCommand(name: 'modules:list', description: 'Lists the modules registered in config/modules.php')]
final class ModulesListCommand extends Command
{
    public function __construct(private readonly string $baseDir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $modules = ModuleRegistry::modules($this->baseDir);
        $inactive = ModuleRegistry::inactive($this->baseDir);

        if ([] === $modules && [] === $inactive) {
            $io->info('No modules registered. Add entries to config/modules.php to plug some in.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($modules as $module) {
            $requires = array_map(
                static fn (string $class): string => substr((string) strrchr('\\'.$class, '\\'), 1),
                $module->requires(),
            );

            $contributes = array_filter([
                [] !== $module->controllers() ? sprintf('%d controllers', \count($module->controllers())) : null,
                [] !== $module->entityPaths() ? 'entities' : null,
                [] !== $module->migrationPaths() ? 'migrations' : null,
                [] !== $module->templatePaths() ? 'templates' : null,
                [] !== $module->translationPaths() ? 'translations' : null,
            ]);

            $envs = ModuleRegistry::environmentsFor($this->baseDir, $module);

            $rows[] = [
                $module->name(),
                $module::class,
                null === $envs ? 'all' : implode(', ', $envs),
                [] === $requires ? '–' : implode(', ', $requires),
                [] === $module->config() ? '–' : implode(', ', array_keys($module->config())),
                [] === $contributes ? '–' : implode(', ', $contributes),
            ];
        }

        // Gated out of this environment: never instantiated, so only the
        // manifest line is known — shown so nobody wonders where it went.
        foreach ($inactive as $entry) {
            $rows[] = [
                '<fg=gray>–</>',
                sprintf('<fg=gray>%s</>', $entry['class']),
                sprintf('<fg=gray>%s</>', implode(', ', $entry['envs'])),
                '<fg=gray>(not in this environment)</>',
                '',
                '',
            ];
        }

        $io->table(['Name', 'Class', 'Envs', 'Requires', 'Config keys', 'Contributes'], $rows);
        $io->text(sprintf('%d module(s), booted in manifest order.', \count($modules)));

        return Command::SUCCESS;
    }
}
