<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Util\ClassNameDetails;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final class DoctrineHelper
{
    public function __construct(
        private string $entityNamespace,
        private EntityManagerInterface $entityManager
    ) {
        $this->entityNamespace = trim($entityNamespace, '\\');

    }

    public function getConnection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getEntityNamespace(): string
    {
        return $this->entityNamespace;
    }

    public function getEntitiesForAutocomplete(): array
    {
        $entities = [];


        $allMetadata = $this->getMetadata();

        foreach (array_keys($allMetadata) as $classname) {
            $entityClassDetails = new ClassNameDetails($classname, $this->entityNamespace);
            $entities[] = $entityClassDetails->getRelativeName();
        }


        sort($entities);

        return $entities;
    }

    public function getMetadata(?string $classOrNamespace = null): array|ClassMetadata
    {
        $metadata = [];
        $cmf = $this->entityManager->getMetadataFactory();

        foreach ($cmf->getAllMetadata() as $m) {
            if (null === $classOrNamespace) {
                $metadata[$m->getName()] = $m;
            } elseif ($m->getName() === $classOrNamespace || str_starts_with($m->getName(), $classOrNamespace)) {
                $metadata[$m->getName()] = $m;
            }
        }

        return $metadata;
    }

    public function getPotentialTableName(string $className): string
    {
        $namingStrategy = $this->entityManager->getConfiguration()->getNamingStrategy();
        return $namingStrategy->classToTableName($className);
    }

    public function isKeyword(string $name): bool
    {
        $connection = $this->entityManager->getConnection();
        return $connection->getDatabasePlatform()->getReservedKeywordsList()->isKeyword($name);
    }

    public static function getTypeConstant(string $columnType): ?string
    {
        $reflection = new \ReflectionClass(Types::class);
        $constants = array_flip($reflection->getConstants());

        return $constants[$columnType] ?? null;
    }

    /**
     * Determines if the property-type will make the column type redundant.
     *
     * See ClassMetadataInfo::validateAndCompleteTypedFieldMapping()
     */
    public static function canColumnTypeBeInferredByPropertyType(string $columnType, string $propertyType): bool
    {
        // todo: guessing on enum's could be added

        return match ($propertyType) {
            '\\' . \DateInterval::class => Types::DATEINTERVAL === $columnType,
            '\\' . \DateTime::class => Types::DATETIME_MUTABLE === $columnType,
            '\\' . \DateTimeImmutable::class => Types::DATETIME_IMMUTABLE === $columnType,
            'array' => Types::JSON === $columnType,
            'bool' => Types::BOOLEAN === $columnType,
            'float' => Types::FLOAT === $columnType,
            'int' => Types::INTEGER === $columnType,
            'string' => Types::STRING === $columnType,
            default => false,
        };
    }

    public static function getPropertyTypeForColumn(string $columnType): ?string
    {
        $propertyType = match ($columnType) {
            Types::STRING, Types::TEXT, Types::GUID, Types::BIGINT, Types::DECIMAL => 'string',
            'array', Types::SIMPLE_ARRAY, Types::JSON => 'array',
            Types::BOOLEAN => 'bool',
            Types::INTEGER, Types::SMALLINT => 'int',
            Types::FLOAT => 'float',
            Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATE_MUTABLE, Types::TIME_MUTABLE => '\\' . \DateTimeInterface::class,
            Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_IMMUTABLE, Types::DATE_IMMUTABLE, Types::TIME_IMMUTABLE => '\\' . \DateTimeImmutable::class,
            Types::DATEINTERVAL => '\\' . \DateInterval::class,
            'object' => 'object',
            'uuid' => '\\' . Uuid::class,
            'ulid' => '\\' . Ulid::class,
            default => null,
        };

        return $propertyType;
    }
}
