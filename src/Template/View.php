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
        if ($this->isUnsafeName($name) === false) {
            foreach ($this->viewPaths as $path) {
                $root = realpath($path);
                if ($root === false) {
                    continue;
                }

                $real = realpath($path . '/' . $name . '.php');
                if (
                    $real !== false &&
                    is_file($real) &&
                    str_starts_with($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR)
                ) {
                    return $real;
                }
            }
        }

        throw new \RuntimeException("View file not found: {$name}");
    }

    /**
     * Whether a view name is unsafe to resolve. Subdirectories are allowed
     * (e.g. "partials/header"); traversal, absolute paths, backslashes and null
     * bytes are rejected.
     */
    protected function isUnsafeName(string $name): bool
    {
        return $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_contains($name, '..')
            || str_starts_with($name, '/');
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

        $file = $this->resolveFile($name);

        ob_start();
        extract($mergedData, EXTR_SKIP);
        include $file;
        return ob_get_clean();
    }
}
