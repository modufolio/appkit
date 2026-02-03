<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Resolver;

use Modufolio\Appkit\Attributes\DataGrid;
use Modufolio\Appkit\DataGrid\ArrayWriter;
use Modufolio\Appkit\DataGrid\Doctrine\QueryBuilderAdapter;
use Modufolio\Appkit\DataGrid\Doctrine\QueryBuilderWriter;
use Modufolio\Appkit\DataGrid\GridMetadata;
use Modufolio\Appkit\DataGrid\GridResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\DataGrid\Compiler;
use Spiral\DataGrid\Grid;
use Spiral\DataGrid\GridFactory;
use Spiral\DataGrid\GridSchema;
use Spiral\DataGrid\Input\ArrayInput;

readonly class DataGridResolver implements AttributeResolverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ServerRequestInterface $request,
    ) {
    }

    public function supports(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        return $type instanceof \ReflectionNamedType
            && $type->getName() === GridResult::class
            && !empty($parameter->getAttributes(DataGrid::class));
    }

    public function resolve(\ReflectionParameter $parameter, array $providedParameters): GridResult
    {
        $attributes = $parameter->getAttributes(DataGrid::class);

        if (empty($attributes)) {
            throw new \LogicException(sprintf(
                'Parameter "%s" does not have the required DataGrid attribute.',
                $parameter->getName()
            ));
        }

        /** @var DataGrid $attr */
        $attr = $attributes[0]->newInstance();

        $schema = $this->resolveSchema($attr->schema);
        $source = $this->resolveSource($attr->source);

        $compiler = new Compiler();

        if ($source instanceof QueryBuilderAdapter) {
            $compiler->addWriter(new QueryBuilderWriter());
        } else {
            $compiler->addWriter(new ArrayWriter());
        }

        $gridFactory = new GridFactory(
            compiler: $compiler,
            input: new ArrayInput($this->request->getQueryParams()),
            view: new Grid(),
        );

        $grid = $gridFactory->create($source, $schema);

        return new GridResult(
            items: iterator_to_array($grid),
            meta: GridMetadata::fromGrid($grid),
        );
    }

    private function resolveSchema(string $schemaClass): GridSchema
    {
        if (!class_exists($schemaClass)) {
            throw new \LogicException(sprintf(
                'Grid schema class "%s" does not exist.',
                $schemaClass
            ));
        }

        $schema = new $schemaClass();

        if (!$schema instanceof GridSchema) {
            throw new \LogicException(sprintf(
                'Grid schema class "%s" must extend %s.',
                $schemaClass,
                GridSchema::class
            ));
        }

        return $schema;
    }

    private function resolveSource(?string $sourceClass): QueryBuilderAdapter|array
    {
        if ($sourceClass === null) {
            return [];
        }

        if (!class_exists($sourceClass)) {
            throw new \LogicException(sprintf(
                'Source class "%s" does not exist.',
                $sourceClass
            ));
        }

        $repository = $this->entityManager->getRepository($sourceClass);
        $queryBuilder = $repository->createQueryBuilder('e');

        return new QueryBuilderAdapter($queryBuilder);
    }
}
