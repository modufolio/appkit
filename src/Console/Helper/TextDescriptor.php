<?php

namespace Modufolio\Appkit\Console\Helper;

use Modufolio\Appkit\Util\MakerFileLinkFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class TextDescriptor extends Descriptor
{
    public function __construct(
        private ?MakerFileLinkFormatter $fileLinkFormatter = null,
    ) {
    }

    protected function describeCallable(mixed $callable, array $options = []): void
    {
        $this->writeText($this->formatCallable($callable), $options);
    }

    protected function describeRouteCollection(RouteCollection $routes, array $options = []): void
    {
        $showControllers = isset($options['show_controllers']) && $options['show_controllers'];

        $tableHeaders = ['Name', 'Method', 'Scheme', 'Host', 'Path'];
        if ($showControllers) {
            $tableHeaders[] = 'Controller';
        }

        $tableRows = [];
        foreach ($routes->all() as $name => $route) {
            $controller = $route->getDefault('_controller');

            $row = [
                $name,
                $route->getMethods() ? implode('|', $route->getMethods()) : 'ANY',
                $route->getSchemes() ? implode('|', $route->getSchemes()) : 'ANY',
                '' !== $route->getHost() ? $route->getHost() : 'ANY',
                $this->formatControllerLink($controller, $route->getPath(), $options['container'] ?? null),
            ];

            if ($showControllers) {
                $row[] = $controller ? $this->formatControllerLink($controller, $this->formatCallable($controller), $options['container'] ?? null) : '';
            }

            $tableRows[] = $row;
        }

        if (isset($options['output'])) {
            $options['output']->table($tableHeaders, $tableRows);
        } else {
            $table = new Table($this->getOutput());
            $table->setHeaders($tableHeaders)->setRows($tableRows);
            $table->render();
        }
    }

    protected function describeRoute(Route $route, array $options = []): void
    {
        $defaults = $route->getDefaults();
        if (isset($defaults['_controller'])) {
            $defaults['_controller'] = $this->formatControllerLink($defaults['_controller'], $this->formatCallable($defaults['_controller']), $options['container'] ?? null);
        }

        $tableHeaders = ['Property', 'Value'];
        $tableRows = [
            ['Route Name', $options['name'] ?? ''],
            ['Path', $route->getPath()],
            ['Path Regex', $route->compile()->getRegex()],
            ['Host', '' !== $route->getHost() ? $route->getHost() : 'ANY'],
            ['Host Regex', '' !== $route->getHost() ? $route->compile()->getHostRegex() : ''],
            ['Scheme', $route->getSchemes() ? implode('|', $route->getSchemes()) : 'ANY'],
            ['Method', $route->getMethods() ? implode('|', $route->getMethods()) : 'ANY'],
            ['Requirements', $route->getRequirements() ? $this->formatRouterConfig($route->getRequirements()) : 'NO CUSTOM'],
            ['Class', $route::class],
            ['Defaults', $this->formatRouterConfig($defaults)],
            ['Options', $this->formatRouterConfig($route->getOptions())],
        ];

        if ('' !== $route->getCondition()) {
            $tableRows[] = ['Condition', $route->getCondition()];
        }

        $table = new Table($this->getOutput());
        $table->setHeaders($tableHeaders)->setRows($tableRows);
        $table->render();
    }

    private function formatRouterConfig(array $config): string
    {
        if (!$config) {
            return 'NONE';
        }

        ksort($config);

        $configAsString = '';
        foreach ($config as $key => $value) {
            $configAsString .= \sprintf("\n%s: %s", $key, $this->formatValue($value));
        }

        return trim($configAsString);
    }

    private function formatControllerLink(mixed $controller, string $anchorText, ?callable $getContainer = null): string
    {
        if (null === $this->fileLinkFormatter) {
            return $anchorText;
        }

        try {
            if (null === $controller) {
                return $anchorText;
            } elseif (\is_array($controller)) {
                $r = new \ReflectionMethod($controller[0], $controller[1]);
            } elseif ($controller instanceof \Closure) {
                $r = new \ReflectionFunction($controller);
            } elseif (method_exists($controller, '__invoke')) {
                $r = new \ReflectionMethod($controller, '__invoke');
            } elseif (!\is_string($controller)) {
                return $anchorText;
            } elseif (str_contains($controller, '::')) {
                $r = new \ReflectionMethod(...explode('::', $controller, 2));
            } else {
                $r = new \ReflectionFunction($controller);
            }
        } catch (\ReflectionException) {
            if (\is_array($controller)) {
                $controller = implode('::', $controller);
            }

            $id = $controller;
            $method = '__invoke';

            if ($pos = strpos($controller, '::')) {
                $id = substr($controller, 0, $pos);
                $method = substr($controller, $pos + 2);
            }

            if (!$getContainer || !($container = $getContainer()) || !$container->has($id)) {
                return $anchorText;
            }

            try {
                $r = new \ReflectionMethod($container->findDefinition($id)->getClass(), $method);
            } catch (\ReflectionException) {
                return $anchorText;
            }
        }

        return $anchorText;
    }

    private function formatCallable(mixed $callable): string
    {
        if (\is_array($callable)) {
            if (\is_object($callable[0])) {
                return \sprintf('%s::%s()', $callable[0]::class, $callable[1]);
            }

            return \sprintf('%s::%s()', $callable[0], $callable[1]);
        }

        if (\is_string($callable)) {
            return \sprintf('%s()', $callable);
        }

        if ($callable instanceof \Closure) {
            $r = new \ReflectionFunction($callable);
            if ($r->isAnonymous()) {
                return 'Closure()';
            }
            if ($class = $r->getClosureCalledClass()) {
                return \sprintf('%s::%s()', $class->name, $r->name);
            }

            return $r->name . '()';
        }

        if (method_exists($callable, '__invoke')) {
            return \sprintf('%s::__invoke()', $callable::class);
        }

        throw new \InvalidArgumentException('Callable is not describable.');
    }

    private function writeText(string $content, array $options = []): void
    {
        $this->write(
            isset($options['raw_text']) && $options['raw_text'] ? strip_tags($content) : $content,
            isset($options['raw_output']) ? !$options['raw_output'] : true
        );
    }
}
