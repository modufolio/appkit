<?php

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Console\Helper\DescriptorHelper;
use Modufolio\Appkit\Routing\RouterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouteCollection;


#[AsCommand(name: 'debug:router', description: 'Display current routes for an application')]
class RouterDebugCommand extends Command
{
    public function __construct(
        private RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputArgument('name', InputArgument::OPTIONAL, 'A route name')
            ])
            ->setHelp(
                <<<'EOF'
The <info>%command.name%</info> displays the configured routes:

  <info>php %command.full_name%</info>
EOF
            )
        ;
    }

    /**
     * @throws InvalidArgumentException When route does not exist
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $helper = new DescriptorHelper();
        $routes = $this->router->getRouteCollection();

        if ($name) {
            $route = $routes->get($name);
            $matchingRoutes = $this->findRouteNameContaining($name, $routes);

            if (!$input->isInteractive() && !$route && \count($matchingRoutes) > 1) {
                $helper->describe($io, $this->findRouteContaining($name, $routes), [
                    'format' => 'txt',
                    'output' => $io,
                ]);

                return 0;
            }

            if (!$route && $matchingRoutes) {
                $default = 1 === \count($matchingRoutes) ? $matchingRoutes[0] : null;
                $name = $io->choice('Select one of the matching routes', $matchingRoutes, $default);
                $route = $routes->get($name);
            }

            if (!$route) {
                throw new InvalidArgumentException(\sprintf('The route "%s" does not exist.', $name));
            }

            $helper->describe($io, $route, [
                'format' => 'txt',
                'name' => $name,
                'output' => $io,
            ]);
        } else {
            $helper->describe($io, $routes, [
                'format' => 'txt',
                'output' => $io,
            ]);
        }

        return 0;
    }

    private function findRouteNameContaining(string $name, RouteCollection $routes): array
    {
        $foundRoutesNames = [];
        foreach ($routes as $routeName => $route) {
            if (false !== stripos($routeName, $name)) {
                $foundRoutesNames[] = $routeName;
            }
        }

        return $foundRoutesNames;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            $suggestions->suggestValues(array_keys($this->router->getRouteCollection()->all()));

            return;
        }

        if ($input->mustSuggestOptionValuesFor('format')) {
            $suggestions->suggestValues($this->getAvailableFormatOptions());
        }
    }

    private function findRouteContaining(string $name, RouteCollection $routes): RouteCollection
    {
        $foundRoutes = new RouteCollection();
        foreach ($routes as $routeName => $route) {
            if (false !== stripos($routeName, $name)) {
                $foundRoutes->add($routeName, $route);
            }
        }

        return $foundRoutes;
    }

    /** @return string[] */
    private function getAvailableFormatOptions(): array
    {
        return ['txt'];
    }
}
