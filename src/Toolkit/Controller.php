<?php

namespace Modufolio\Appkit\Toolkit;

/**
 * A smart extension of Closures with
 * magic dependency injection based on the
 * defined variable names.
 *
 * Inspired by Kirby CMS Controller
 */
class Controller
{
    public function __construct(
        protected \Closure $function
    ) {
    }

    /**
     * Get arguments for the closure based on available data
     * @throws \ReflectionException
     */
    public function arguments(array $data = []): array
    {
        $info = new \ReflectionFunction($this->function);
        $args = [];

        foreach ($info->getParameters() as $param) {
            $name = $param->getName();

            if ($param->isVariadic() === true) {
                // Variadic ... argument collects all remaining values
                $args += $data;
            } elseif (isset($data[$name]) === true) {
                // Use provided argument value if available
                $args[$name] = $data[$name];
            } elseif ($param->isDefaultValueAvailable() === false) {
                // Use null for any other arguments that don't define
                // a default value for themselves
                $args[$name] = null;
            }
        }

        return $args;
    }

    /**
     * Call the closure with dependency injection
     */
    public function call($bind = null, $data = []): mixed
    {
        // Get matched arguments based on parameter names
        $args = $this->arguments($data);

        if ($bind === null) {
            return ($this->function)(...$args);
        }

        return $this->function->call($bind, ...$args);
    }

    /**
     * Load a controller from a PHP file
     */
    public static function load(string $file, ?string $in = null): ?static
    {
        if (!is_file($file)) {
            return null;
        }

        // Restrict file paths to the provided root to prevent path traversal
        if ($in !== null && !str_starts_with(realpath($file), realpath($in))) {
            return null;
        }

        $function = require $file;

        if (!$function instanceof \Closure) {
            return null;
        }

        return new static($function);
    }
}
