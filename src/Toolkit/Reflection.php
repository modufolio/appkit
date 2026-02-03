<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Toolkit;

class Reflection
{
    public static function callable($callable): \ReflectionFunctionAbstract
    {
        // Closure
        if ($callable instanceof \Closure) {
            return new \ReflectionFunction($callable);
        }

        // Array callable
        if (is_array($callable)) {
            [$class, $method] = $callable;

            if (! method_exists($class, $method)) {
                throw new \InvalidArgumentException(sprintf(
                    'Method %s::%s does not exist',
                    is_object($class) ? get_class($class) : $class,
                    $method
                ));
            }

            return new \ReflectionMethod($class, $method);
        }

        // Callable object (i.e. implementing __invoke())
        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        // Standard function
        if (is_string($callable) && function_exists($callable)) {
            return new \ReflectionFunction($callable);
        }

        throw new \InvalidArgumentException(sprintf(
            'Callable is not resolvable: %s',
            gettype($callable)
        ));
    }

    public static function sortArguments(\ReflectionFunctionAbstract $info, $data): array
    {
        $args = [];
        foreach ($info->getParameters() as $param) {
            $name = $param->getName();
            if (isset($data[$name]) === true) {
                $args[$name] = $data[$name];
            }
        }

        return $args;
    }

}
