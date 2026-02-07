<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\MapFilter;
use Modufolio\Appkit\Attributes\MapQueryString;
use Modufolio\Appkit\Attributes\MapRequestPayload;
use Modufolio\Appkit\Form\ValidationResult;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

readonly class MapRequestPayloadResolver implements AttributeResolverInterface
{
    public function __construct(
        private DenormalizerInterface $serializer,
        private ServerRequestInterface $request,
        private ValidatorInterface $validator
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        return !empty($parameter->getAttributes(MapRequestPayload::class)) ||
            !empty($parameter->getAttributes(MapQueryString::class)) ||
            !empty($parameter->getAttributes(MapFilter::class));
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function resolve(\ReflectionParameter $parameter, array $providedParameters): object
    {
        $mapRequestPayload = $parameter->getAttributes(MapRequestPayload::class)[0] ?? null;
        $mapQueryString = $parameter->getAttributes(MapQueryString::class)[0] ?? null;
        $mapFilter = $parameter->getAttributes(MapFilter::class)[0] ?? null;

        if ($mapRequestPayload) {
            return $this->resolveRequestPayload($parameter, $mapRequestPayload->newInstance());
        }

        if ($mapQueryString) {
            return $this->resolveQueryString($parameter, $mapQueryString->newInstance());
        }

        if ($mapFilter) {
            return $this->resolveFilter($parameter, $mapFilter->newInstance());
        }

        throw new \LogicException('Unsupported attribute');
    }

    /**
     * @throws \JsonException
     * @throws \Exception
     */
    private function resolveRequestPayload(\ReflectionParameter $parameter, MapRequestPayload $attribute): object
    {
        $array = $this->request->getParsedBody() ?? [];
        $className = $this->getClassName($parameter);

        $violations = new ConstraintViolationList();

        try {
            $payload = $this->serializer->denormalize($array, $className, 'array');
        } catch (PartialDenormalizationException $e) {
            $violations = $this->handlePartialDenormalization($e);
            $payload = $e->getData();
        }

        $violations->addAll($this->validator->validate($payload));

        if ($violations->count() > 0) {
            if (!$attribute->throwOnError) {
                return new ResolvedPayload($payload, new ValidationResult($violations));
            }

            throw new ValidationFailedException($payload, $violations);
        }

        if (!$attribute->throwOnError) {
            return new ResolvedPayload($payload);
        }

        return $payload;
    }

    private function resolveQueryString(\ReflectionParameter $parameter, MapQueryString $attribute): object
    {
        $queryParams = $this->request->getQueryParams();
        $className = $this->getClassName($parameter);

        if ($attribute->name) {
            $queryParams = $queryParams[$attribute->name] ?? [];
        }

        $payload = $this->serializer->denormalize($queryParams, $className, 'array');

        $violations = $this->validator->validate($payload);

        if ($violations->count() > 0) {
            if (!$attribute->throwOnError) {
                return new ResolvedPayload($payload, new ValidationResult($violations));
            }

            throw new ValidationFailedException($payload, $violations);
        }

        if (!$attribute->throwOnError) {
            return new ResolvedPayload($payload);
        }

        return $payload;
    }

    private function resolveFilter(\ReflectionParameter $parameter, MapFilter $attribute): object
    {
        $queryParams = $this->request->getQueryParams();
        $className = $this->getClassName($parameter);

        $filter = new $className();

        assert(
            $filter instanceof MapFilterInterface,
            'Filter class must implement MapFilterInterface'
        );

        return $filter->fromArray($queryParams);
    }

    private function getClassName(\ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException(sprintf(
                'Parameter "%s" must have a named type hint.',
                $parameter->getName()
            ));
        }

        return $type->getName();
    }

    private function handlePartialDenormalization(PartialDenormalizationException $e): ConstraintViolationList
    {
        $violations = new ConstraintViolationList();
        $trans = static fn ($m, $p) => strtr($m, $p);
        foreach ($e->getErrors() as $error) {
            $parameters = [];
            $template = 'This value was of an unexpected type.';
            if ($expectedTypes = $error->getExpectedTypes()) {
                $template = 'This value should be of type {{ type }}.';
                $parameters['{{ type }}'] = implode('|', $expectedTypes);
            }
            if ($error->canUseMessageForUser()) {
                $parameters['hint'] = $error->getMessage();
            }
            $message = $trans($template, $parameters);
            $violations->add(new ConstraintViolation($message, $template, $parameters, null, $error->getPath(), null));
        }
        return $violations;
    }
}
