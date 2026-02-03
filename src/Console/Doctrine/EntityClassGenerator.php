<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Console\Doctrine;

use Modufolio\Appkit\Console\Generator;
use Modufolio\Appkit\Console\Str;
use Modufolio\Appkit\Util\ClassNameDetails;
use Modufolio\Appkit\Util\UseStatementGenerator;
use Doctrine\ORM\EntityRepository;

final class EntityClassGenerator
{
    public function __construct(
        private Generator $generator,
        private DoctrineHelper $doctrineHelper,
    ) {
    }

    public function generateEntityClass(ClassNameDetails $entityClassDetails, bool $generateRepositoryClass = true): string
    {
        $repoClassDetails = $this->generator->createClassNameDetails(
            $entityClassDetails->getRelativeName(),
            'Repository\\',
            'Repository'
        );

        $tableName = $this->doctrineHelper->getPotentialTableName($entityClassDetails->getFullName());

        $useStatements = new UseStatementGenerator([
            $repoClassDetails->getFullName(),
            ['Doctrine\\ORM\\Mapping' => 'ORM'],
        ]);

        $entityPath = $this->generator->generateClass(
            $entityClassDetails->getFullName(),
            'doctrine/Entity.tpl.php',
            [
                'use_statements' => $useStatements,
                'repository_class_name' => $repoClassDetails->getShortName(),
                'should_escape_table_name' => $this->doctrineHelper->isKeyword($tableName),
                'table_name' => $tableName,
            ]
        );

        if ($generateRepositoryClass) {
            $this->generateRepositoryClass(
                $repoClassDetails->getFullName(),
                $entityClassDetails->getFullName(),
            );
        }

        return $entityPath;
    }

    public function generateRepositoryClass(string $repositoryClass, string $entityClass): void
    {
        $shortEntityClass = Str::getShortClassName($entityClass);
        $entityAlias = strtolower($shortEntityClass[0]);

        $useStatements = new UseStatementGenerator([
            $entityClass,
            EntityRepository::class,
        ]);

        $this->generator->generateClass(
            $repositoryClass,
            'doctrine/Repository.tpl.php',
            [
                'use_statements' => $useStatements,
                'entity_class_name' => $shortEntityClass,
                'entity_alias' => $entityAlias,
            ]
        );
    }
}
