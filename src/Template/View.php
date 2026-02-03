<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Template;

use Throwable;

/**
 * Self-contained View class - RoadRunner-safe
 *
 * Simple view/snippet renderer with instance-based state.
 * No static properties = no memory leaks in long-running workers.
 *
 * @package   Appkit Core
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class View
{
    protected array $viewPaths = [];
    protected array $data = [];

    public function __construct(array $viewPaths = [], array $data = [])
    {
        $this->viewPaths = $viewPaths;
        $this->data = $data;
    }

    /**
     * Add a view path
     */
    public function addViewPath(string $path): self
    {
        $this->viewPaths[] = rtrim($path, '/');
        return $this;
    }

    /**
     * Resolve the view file
     *
     * @param string $name
     * @return string
     * @throws \RuntimeException
     */
    protected function resolveFile(string $name): string
    {
        foreach ($this->viewPaths as $path) {
            $file = "{$path}/{$name}.php";
            if (file_exists($file)) {
                return $file;
            }
        }

        throw new \RuntimeException("View file not found: {$name}");
    }

    /**
     * Render a view (snippet)
     *
     * @param string $name
     * @param array $data
     * @return string|null
     * @throws Throwable
     */
    public function render(string $name, array $data = []): ?string
    {
        $mergedData = array_merge($this->data, $data);

        ob_start();
        extract($mergedData);
        include $this->resolveFile($name);
        return ob_get_clean();
    }
}
