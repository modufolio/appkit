<?php

namespace Modufolio\Appkit\Console\Helper;

use Symfony\Component\Console\Descriptor\DescriptorInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

abstract class Descriptor implements DescriptorInterface
{
    protected OutputInterface $output;

    public function describe(OutputInterface $output, mixed $object, array $options = []): void
    {
        $this->output = $output;

        match (true) {
            $object instanceof RouteCollection => $this->describeRouteCollection($object, $options),
            $object instanceof Route => $this->describeRoute($object, $options),
            \is_callable($object) => $this->describeCallable($object, $options),
            default => throw new \InvalidArgumentException(\sprintf('Object of type "%s" is not describable.', get_debug_type($object))),
        };
    }

    protected function getOutput(): OutputInterface
    {
        return $this->output;
    }

    protected function write(string $content, bool $decorated = false): void
    {
        $this->output->write($content, false, $decorated ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_RAW);
    }

    abstract protected function describeRouteCollection(RouteCollection $routes, array $options = []): void;

    abstract protected function describeRoute(Route $route, array $options = []): void;


    abstract protected function describeCallable(mixed $callable, array $options = []): void;

    protected function formatValue(mixed $value): string
    {
        if ($value instanceof \UnitEnum) {
            return ltrim(var_export($value, true), '\\');
        }

        if (\is_object($value)) {
            return \sprintf('object(%s)', $value::class);
        }

        if (\is_string($value)) {
            return $value;
        }

        return preg_replace("/\n\s*/s", '', var_export($value, true));
    }

    protected function formatParameter(mixed $value): string
    {
        if ($value instanceof \UnitEnum) {
            return ltrim(var_export($value, true), '\\');
        }

        // Recursively search for enum values, so we can replace it
        // before json_encode (which will not display anything for \UnitEnum otherwise)
        if (\is_array($value)) {
            array_walk_recursive($value, static function (&$value) {
                if ($value instanceof \UnitEnum) {
                    $value = ltrim(var_export($value, true), '\\');
                }
            });
        }

        if (\is_bool($value) || \is_array($value) || (null === $value)) {
            $jsonString = json_encode($value);

            if (preg_match('/^(.{60})./us', $jsonString, $matches)) {
                return $matches[1] . '...';
            }

            return $jsonString;
        }

        return (string)$value;
    }

}
