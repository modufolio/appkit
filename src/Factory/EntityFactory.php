<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Factory;

use Modufolio\Appkit\Toolkit\A;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EntityFactory
{
    private array $config = [];


    public function __construct(
        private EntityManagerInterface $entityManager,
        private DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private array $resolverArgs = []
    ) {


        // Default Faker if not provided
        $this->resolverArgs['faker'] = Factory::create();
    }

    public function loadConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function create(string $className, array $attributes = []): self
    {
        if (!$this->has($className)) {
            throw new \InvalidArgumentException("No configuration found for class $className.");
        }

        $defaults = $this->resolveDefaults($className);
        $data = array_merge($defaults, $attributes);
        $data = A::apply($data);

        // Handle relations explicitly
        $data = $this->resolveRelations($className, $data);

        $entity = $this->serializer->denormalize($data, $className);

        $this->validate($entity);
        $this->entityManager->persist($entity);

        return $this;
    }

    private function resolveRelations(string $className, array $data): array
    {
        $metadata = $this->entityManager->getClassMetadata($className);

        foreach ($data as $field => $value) {
            if ($metadata->hasAssociation($field) && is_array($value)) {
                $targetClass = $metadata->getAssociationTargetClass($field);

                $data[$field] = $this->serializer->denormalize($value, $targetClass);
            }
        }

        return $data;
    }

    public function createMany(string $className, int $count, array|callable $attributes = []): self
    {
        for ($i = 0; $i < $count; $i++) {
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



    private function resolveDefaults(string $className): array
    {
        $config = $this->config[$className] ?? [];
        $fields = $config['fields'] ?? [];
        return A::apply($fields, ...$this->getResolverArgs());
    }

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

    public function withResolverArgs(array $args): self
    {
        $this->resolverArgs = array_merge($this->resolverArgs, $args);
        return $this;
    }
}
