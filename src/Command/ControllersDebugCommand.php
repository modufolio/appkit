<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Core\AppInterface;
use Modufolio\Appkit\Routing\RouterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates that every controller referenced by a configured route can
 * actually be wired from the DI container — catching missing/mismatched
 * constructor arguments before they surface as a 500 in production.
 */
#[AsCommand(
    name: 'debug:controllers',
    description: 'Checks that every controller referenced by a route can be wired from the container'
)]
final class ControllersDebugCommand extends Command
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Controller wiring check');

        $this->app->initializeConsoleState();

        $ids = $this->collectControllerIds();

        if ($ids === []) {
            $io->warning('No controllers are referenced by the configured routes.');
            return Command::SUCCESS;
        }

        $rows = [];
        $failures = 0;

        foreach ($ids as $id) {
            try {
                $this->app->getController($id);
                $rows[] = ['<info>OK</info>', $id, ''];
            } catch (\Throwable $e) {
                $failures++;
                $rows[] = ['<error>FAIL</error>', $id, $e->getMessage()];
            }
        }

        $io->table(['Status', 'Controller', 'Error'], $rows);

        $total = count($ids);

        if ($failures > 0) {
            $io->error(sprintf('%d of %d controllers could not be wired.', $failures, $total));
            return Command::FAILURE;
        }

        $io->success(sprintf('All %d controllers wired successfully.', $total));
        return Command::SUCCESS;
    }

    /**
     * Walk every configured route and collect the distinct controller
     * class names referenced by its `_controller` default.
     *
     * @return string[]
     */
    private function collectControllerIds(): array
    {
        $ids = [];

        foreach ($this->router->getRouteCollection() as $route) {
            $controller = $route->getDefault('_controller');

            if (is_array($controller) && isset($controller[0]) && is_string($controller[0])) {
                $ids[$controller[0]] = true;
            }
        }

        return array_keys($ids);
    }
}
