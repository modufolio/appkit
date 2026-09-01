<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use Modufolio\Appkit\Toolkit\A;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final class EntityFactory
{
    /** @var array<class-string, array<string, mixed>> */
    private array $config = [];

    /**
     * @param array<string, mixed> $resolverArgs
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private array $resolverArgs = [],
    ) {
        $this->resolverArgs['faker'] = Factory::create();
    }

    /**
     * @param array<class-string, array<string, mixed>> $config
     */
    public function loadConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $attributes
     *
     * @throws ExceptionInterface
     */
    public function create(string $className, array $attributes = []): self
    {
        if (!$this->has($className)) {
            throw new \InvalidArgumentException("No configuration found for class {$className}.");
        }

        $defaults = $this->resolveDefaults($className);
        $data = array_merge($defaults, $attributes);
        $data = A::apply($data);

        $data = $this->resolveRelations($className, $data);

        $entity = $this->serializer->denormalize($data, $className);

        $this->validate($entity);
        $this->entityManager->persist($entity);

        return $this;
    }

    /**
     * @param class-string         $className
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws ExceptionInterface
     */
    private function resolveRelations(string $className, array $data): array
    {
        $metadata = $this->entityManager->getClassMetadata($className);

        foreach ($data as $field => $value) {
            if (!($metadata->hasAssociation($field) && is_array($value))) {
                continue;
            }

            $targetClass = $metadata->getAssociationTargetClass($field);

            $data[$field] = $this->serializer->denormalize($value, $targetClass);
        }

        return $data;
    }

    /**
     * @param class-string                                             $className
     * @param array<string, mixed>|callable(int): array<string, mixed> $attributes
     */
    public function createMany(string $className, int $count, array|callable $attributes = []): self
    {
        for ($i = 0; $i < $count; ++$i) {
            $attrs = is_callable($attributes) ? $attributes($i) : $attributes;
            $this->create($className, $attrs);
        }

        return $this;
    }

    public function has(string $className): bool
    {
        return isset($this->config[$className]);
    }

    public function store(): self
    {
        $this->entityManager->flush();

        return $this;
    }

    /**
     * @param class-string $className
     *
     * @return array<string, mixed>
     */
    private function resolveDefaults(string $className): array
    {
        $config = $this->config[$className] ?? [];
        $fields = $config['fields'] ?? [];

        return A::apply($fields, ...$this->getResolverArgs());
    }

    /**
     * @return list<mixed>
     */
    private function getResolverArgs(): array
    {
        return array_values($this->resolverArgs);
    }

    private function validate(object $entity): void
    {
        $violations = $this->validator->validate($entity);

        if (count($violations) > 0) {
            throw new ValidationFailedException($entity, $violations);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public function withResolverArgs(array $args): self
    {
        $this->resolverArgs = array_merge($this->resolverArgs, $args);

        return $this;
    }
}
