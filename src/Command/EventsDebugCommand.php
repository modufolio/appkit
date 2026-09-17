<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Core\AppInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What listens to what, and in which order.
 *
 * Reads the resolved listener map rather than a built dispatcher, so it shows
 * the priority that decided the order and dispatches nothing. Describes the
 * listeners the kernel wires; the container layer knows its own.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[AsCommand(
    name: 'debug:events',
    description: 'Display the listeners wired for each event, highest priority first'
)]
final class EventsDebugCommand extends Command
{
    public function __construct(private readonly AppInterface $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'event',
            InputArgument::OPTIONAL,
            'Show only events whose name contains this (case-insensitive)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Event listeners');

        $map = $this->app->listenerMap();

        if ([] === $map) {
            $io->warning('No listeners are declared. config/events.php is where they are imported.');

            return Command::SUCCESS;
        }

        $filter = $input->getArgument('event');
        $filter = \is_string($filter) && '' !== $filter ? $filter : null;

        // Grouped for reading; the map is flat because that is how it is
        // declared and cached.
        $byEvent = [];
        foreach ($map as $name => $definition) {
            if (null !== $filter && !str_contains(strtolower($definition['event']), strtolower($filter))) {
                continue;
            }

            $byEvent[$definition['event']][] = ['name' => $name] + $definition;
        }

        if ([] === $byEvent) {
            $io->warning(sprintf('No event matches "%s".', (string) $filter));

            return Command::SUCCESS;
        }

        ksort($byEvent);

        foreach ($byEvent as $event => $listeners) {
            $io->section($event);

            $rows = [];
            foreach ($listeners as $order => $listener) {
                [$class, $method] = $listener['listener'];
                $rows[] = [$order + 1, $listener['priority'], $class.'::'.$method.'()'];
            }

            $io->table(['#', 'Priority', 'Listener'], $rows);
        }

        $shown = array_sum(array_map('count', $byEvent));
        $io->success(sprintf('%d listener(s) across %d event(s).', $shown, \count($byEvent)));

        return Command::SUCCESS;
    }
}
