<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Console\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Modufolio\Appkit\Console\FileManager;
use Modufolio\Appkit\Console\Generator;
use Modufolio\Appkit\Exception\RuntimeCommandException;
use Modufolio\Appkit\Util\ClassSource\Model\ClassProperty;
use Modufolio\Appkit\Util\ClassSourceManipulator;

/**
 * @see       https://github.com/symfony/maker-bundle
 *
 * @copyright Fabien Potencier <fabien@symfony.com>
 * @license   https://opensource.org/licenses/MIT
 */
final class EntityRegenerator
{
    public function __construct(
        private DoctrineHelper $doctrineHelper,
        private FileManager $fileManager,
        private Generator $generator,
        private EntityClassGenerator $entityClassGenerator,
        private bool $overwrite,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function regenerateEntities(string $classOrNamespace): void
    {
        $metadata = $this->doctrineHelper->getMetadata($classOrNamespace);

        if ($metadata instanceof ClassMetadata) {
            $metadata = [$metadata];
        } elseif (class_exists($classOrNamespace)) {
            throw new RuntimeCommandException(\sprintf('Could not find Doctrine metadata for "%s". Is it mapped as an entity?', $classOrNamespace));
        } elseif (empty($metadata)) {
            throw new RuntimeCommandException(\sprintf('No entities were found in the "%s" namespace.', $classOrNamespace));
        }

        /** @var ClassSourceManipulator[] $operations */
        $operations = [];
        foreach ($metadata as $classMetadata) {
            if (!class_exists($classMetadata->name)) {
                // the class needs to be generated for the first time!
                $classPath = $this->generateClass($classMetadata);
            } else {
                $classPath = $this->getPathOfClass($classMetadata->name);
            }

            $mappedFields = $this->getMappedFieldsInEntity($classMetadata);

            if ($classMetadata->customRepositoryClassName) {
                $this->generateRepository($classMetadata);
            }

            $manipulator = $this->createClassManipulator($classPath);
            $operations[$classPath] = $manipulator;

            $embeddedClasses = [];

            foreach ($classMetadata->embeddedClasses as $fieldName => $mapping) {
                if (str_contains($fieldName, '.')) {
                    continue;
                }

                $className = $mapping->class;

                $embeddedClasses[$fieldName] = $this->getPathOfClass($className);

                $operations[$embeddedClasses[$fieldName]] = $this->createClassManipulator($embeddedClasses[$fieldName]);

                if (!\in_array($fieldName, $mappedFields)) {
                    continue;
                }

                $manipulator->addEmbeddedEntity($fieldName, $className);
            }

            foreach ($classMetadata->fieldMappings as $fieldName => $mapping) {
                // skip embedded fields
                if (str_contains($fieldName, '.')) {
                    [$fieldName, $embeddedFiledName] = explode('.', $fieldName);

                    $property = ClassProperty::createFromObject($mapping);
                    $property->propertyName = $embeddedFiledName;

                    $operations[$embeddedClasses[$fieldName]]->addEntityField($property);

                    continue;
                }

                if (!\in_array($fieldName, $mappedFields)) {
                    continue;
                }

                $manipulator->addEntityField(ClassProperty::createFromObject($mapping));
            }

            foreach ($classMetadata->associationMappings as $fieldName => $mapping) {
                if (!\in_array($fieldName, $mappedFields)) {
                    continue;
                }

                match (true) {
                    $mapping instanceof ManyToOneAssociationMapping => $manipulator->addManyToOneRelation(RelationManyToOne::createFromObject($mapping)),
                    $mapping instanceof OneToManyAssociationMapping => $manipulator->addOneToManyRelation(RelationOneToMany::createFromObject($mapping)),
                    $mapping instanceof ManyToManyAssociationMapping => $manipulator->addManyToManyRelation(RelationManyToMany::createFromObject($mapping)),
                    default => $manipulator->addOneToOneRelation(RelationOneToOne::createFromObject($mapping)),
                };
            }
        }

        foreach ($operations as $filename => $manipulator) {
            $this->fileManager->dumpFile(
                $filename,
                $manipulator->getSourceCode()
            );
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function generateClass(ClassMetadata $metadata): string
    {
        $path = $this->generator->generateClass(
            $metadata->name,
            'Class.tpl.php',
            []
        );
        $this->generator->writeChanges();

        return $path;
    }

    private function createClassManipulator(string $classPath): ClassSourceManipulator
    {
        return new ClassSourceManipulator(
            sourceCode: $this->fileManager->getFileContents($classPath),
            overwrite: $this->overwrite,
            // if properties need to be generated then, by definition,
            // some non-annotation config is being used (e.g. XML), and so, the
            // properties should not have annotations added to them
            useAttributesForDoctrineMapping: false
        );
    }

    /**
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    private function getPathOfClass(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();

        if (false === $file) {
            throw new \RuntimeException(\sprintf('Class "%s" is not defined in a file.', $class));
        }

        return $file;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function generateRepository(ClassMetadata $metadata): void
    {
        if (!$metadata->customRepositoryClassName) {
            return;
        }

        if (class_exists($metadata->customRepositoryClassName)) {
            // repository already exists
            return;
        }

        $this->entityClassGenerator->generateRepositoryClass(
            $metadata->customRepositoryClassName,
            $metadata->name
        );

        $this->generator->writeChanges();
    }

    /**
     * @param ClassMetadata<object> $classMetadata
     *
     * @return list<string>
     */
    private function getMappedFieldsInEntity(ClassMetadata $classMetadata): array
    {
        $classReflection = $classMetadata->reflClass;

        $targetFields = [
            ...array_keys($classMetadata->fieldMappings),
            ...array_keys($classMetadata->associationMappings),
            ...array_keys($classMetadata->embeddedClasses),
        ];

        if (null === $classReflection) {
            throw new \RuntimeException(\sprintf('Entity "%s" has no reflection class available.', $classMetadata->getName()));
        }

        // exclude traits
        $traitProperties = [];

        foreach ($classReflection->getTraits() as $trait) {
            foreach ($trait->getProperties() as $property) {
                $traitProperties[] = $property->getName();
            }
        }

        $targetFields = array_diff($targetFields, $traitProperties);

        // exclude inherited properties
        return array_values(array_filter($targetFields, static fn ($field) => $classReflection->hasProperty($field)
            && $classReflection->getProperty($field)->getDeclaringClass()->getName() === $classReflection->getName()));
    }
}
