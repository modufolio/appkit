<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\MapQueryParameter;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Resolves a controller argument from a single query parameter, using the
 * #[MapQueryParameter] attribute. Values are coerced with filter_var() based
 * on the argument's type hint.
 *
 * A missing parameter falls back to the argument default or null when the
 * argument is nullable, otherwise a 400 (\InvalidArgumentException) is thrown.
 * An invalid value throws a 400 unless FILTER_NULL_ON_FAILURE is set in flags.
 */
readonly class MapQueryParameterResolver implements AttributeResolverInterface
{
    public function __construct(
        private ServerRequestInterface $request,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return !empty($parameter->getAttributes(MapQueryParameter::class));
    }

    public function resolve(\ReflectionParameter $parameter, array $providedParameters): mixed
    {
        $attribute = $parameter->getAttributes(MapQueryParameter::class)[0]->newInstance();
        $name = $attribute->name ?? $parameter->getName();
        $queryParams = $this->request->getQueryParams();

        if (!array_key_exists($name, $queryParams)) {
            return $this->resolveMissing($parameter, $name);
        }

        return $this->filterValue($parameter, $attribute, $name, $queryParams[$name]);
    }

    private function resolveMissing(\ReflectionParameter $parameter, string $name): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \InvalidArgumentException(sprintf('Missing query parameter "%s".', $name));
    }

    private function filterValue(\ReflectionParameter $parameter, MapQueryParameter $attribute, string $name, mixed $value): mixed
    {
        $type = $this->typeName($parameter);

        // Plain array with no explicit filter: keep the raw array.
        if (null === $attribute->filter && 'array' === $type) {
            return (array) $value;
        }

        $options = [
            'flags' => $attribute->flags | \FILTER_NULL_ON_FAILURE,
            'options' => $attribute->options,
        ];

        if ('array' === $type) {
            $value = (array) $value;
            $options['flags'] |= \FILTER_REQUIRE_ARRAY;
        } else {
            $options['flags'] |= \FILTER_REQUIRE_SCALAR;
        }

        $uidType = null;
        if (null !== $type && is_subclass_of($type, AbstractUid::class)) {
            $uidType = $type;
            $type = 'uid';
        }

        $enumClass = null;
        $filter = match ($type) {
            'array' => \FILTER_DEFAULT,
            'string' => isset($attribute->options['regexp']) ? \FILTER_VALIDATE_REGEXP : \FILTER_DEFAULT,
            'int' => \FILTER_VALIDATE_INT,
            'float' => \FILTER_VALIDATE_FLOAT,
            'bool' => \FILTER_VALIDATE_BOOL,
            'uid' => \FILTER_DEFAULT,
            default => $this->enumFilter($parameter, $type, $name, $enumClass),
        };

        $value = filter_var($value, $attribute->filter ?? $filter, $options);

        if (null !== $enumClass && null !== $value) {
            $value = $enumClass::tryFrom($value);
        }

        if (null !== $uidType && null !== $value) {
            try {
                $value = $uidType::fromString($value);
            } catch (\InvalidArgumentException) {
                $value = null;
            }
        }

        if (null === $value && !($attribute->flags & \FILTER_NULL_ON_FAILURE)) {
            throw new \InvalidArgumentException(sprintf('Invalid query parameter "%s".', $name));
        }

        return $value;
    }

    /**
     * Determines the filter for a BackedEnum type, capturing the enum class by reference
     * so the value can be converted after filtering.
     */
    private function enumFilter(\ReflectionParameter $parameter, ?string $type, string $name, ?string &$enumClass): int
    {
        if (null !== $type && is_subclass_of($type, \BackedEnum::class)) {
            $enumClass = $type;

            return 'int' === (new \ReflectionEnum($type))->getBackingType()?->getName()
                ? \FILTER_VALIDATE_INT
                : \FILTER_DEFAULT;
        }

        throw new \LogicException(sprintf('#[MapQueryParameter] cannot be used on parameter "$%s" of type "%s"; one of array, string, int, float, bool, uid or \BackedEnum should be used.', $parameter->getName(), $type ?? 'mixed'));
    }

    private function typeName(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType ? $type->getName() : null;
    }
}
