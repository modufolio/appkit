<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Attributes\Sensitive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;

#[AsCommand(
    name: 'app:sensitive:list',
    description: 'Lists all fields marked with the #[Sensitive] attribute for compliance auditing'
)]
class SensitiveListCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('classification', 'c', InputOption::VALUE_REQUIRED, 'Filter by classification (public, internal, confidential, secret, regulated)')
            ->addOption('protection', 'p', InputOption::VALUE_REQUIRED, 'Filter by protection type (encrypt, mask, hash, none)')
            ->addOption('purpose', null, InputOption::VALUE_REQUIRED, 'Filter by purpose (KYC, AML, PCI, etc.)')
            ->addOption('entity', 'e', InputOption::VALUE_REQUIRED, 'Filter by entity name')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Group by: entity (default), classification, protection, purpose', 'entity')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table (default), json', 'table');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('sensitive-list-command');

        $fields = $this->collectSensitiveFields();
        $fields = $this->applyFilters($fields, $input);

        if (empty($fields)) {
            $this->io->note('No sensitive fields found matching the criteria.');
            return Command::SUCCESS;
        }

        $format = $input->getOption('format');
        $groupBy = $input->getOption('group');

        if ($format === 'json') {
            $this->outputJson($fields, $output);
        } else {
            $this->outputTable($fields, $groupBy);
        }

        $this->displayVerboseOutput($fields, $stopwatch, $output);

        return Command::SUCCESS;
    }

    /**
     * Collects all fields marked with #[Sensitive] from all entities.
     *
     * @return array<int, array{entity: string, field: string, classification: string, protection: string, purpose: string|null, retention: string|null}>
     */
    private function collectSensitiveFields(): array
    {
        $fields = [];
        $metadataFactory = $this->entityManager->getMetadataFactory();

        foreach ($metadataFactory->getAllMetadata() as $metadata) {
            $className = $metadata->getName();
            $shortName = $metadata->getReflectionClass()->getShortName();

            foreach ($metadata->getReflectionProperties() as $property) {
                $attributes = $property->getAttributes(Sensitive::class);

                if (empty($attributes)) {
                    continue;
                }

                $sensitive = $attributes[0]->newInstance();

                $fields[] = [
                    'entity' => $shortName,
                    'entityClass' => $className,
                    'field' => $property->getName(),
                    'classification' => $sensitive->classification,
                    'protection' => $sensitive->protection,
                    'purpose' => $sensitive->purpose,
                    'retention' => $sensitive->retention,
                ];
            }
        }

        // Sort by entity, then field
        usort($fields, fn($a, $b) => [$a['entity'], $a['field']] <=> [$b['entity'], $b['field']]);

        return $fields;
    }

    /**
     * Applies filters based on input options.
     */
    private function applyFilters(array $fields, InputInterface $input): array
    {
        $classification = $input->getOption('classification');
        $protection = $input->getOption('protection');
        $purpose = $input->getOption('purpose');
        $entity = $input->getOption('entity');

        return array_filter($fields, function (array $field) use ($classification, $protection, $purpose, $entity): bool {
            if ($classification !== null && $field['classification'] !== $classification) {
                return false;
            }
            if ($protection !== null && $field['protection'] !== $protection) {
                return false;
            }
            if ($purpose !== null && $field['purpose'] !== $purpose) {
                return false;
            }
            if ($entity !== null && stripos($field['entity'], $entity) === false) {
                return false;
            }
            return true;
        });
    }

    /**
     * Outputs fields as JSON.
     */
    private function outputJson(array $fields, OutputInterface $output): void
    {
        // Remove internal entityClass for cleaner output
        $cleanFields = array_map(function (array $field): array {
            unset($field['entityClass']);
            return $field;
        }, $fields);

        $output->writeln(json_encode(array_values($cleanFields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Outputs fields as formatted tables.
     */
    private function outputTable(array $fields, string $groupBy): void
    {
        $this->io->title('Sensitive Data Fields Audit');

        $grouped = $this->groupFields($fields, $groupBy);

        foreach ($grouped as $groupName => $groupFields) {
            $count = count($groupFields);
            $this->io->section(sprintf('%s (%d %s)', $groupName, $count, $count === 1 ? 'field' : 'fields'));

            $headers = $this->getTableHeaders($groupBy);
            $rows = $this->formatTableRows($groupFields, $groupBy);

            $this->io->table($headers, $rows);
        }

        $this->displaySummary($fields);
    }

    /**
     * Groups fields by the specified key.
     */
    private function groupFields(array $fields, string $groupBy): array
    {
        $grouped = [];

        foreach ($fields as $field) {
            $key = match ($groupBy) {
                'classification' => $field['classification'],
                'protection' => $field['protection'],
                'purpose' => $field['purpose'] ?? '(no purpose)',
                default => $field['entity'],
            };

            $grouped[$key][] = $field;
        }

        ksort($grouped);
        return $grouped;
    }

    /**
     * Gets table headers based on grouping.
     */
    private function getTableHeaders(string $groupBy): array
    {
        return match ($groupBy) {
            'entity' => ['Field', 'Classification', 'Protection', 'Purpose', 'Retention'],
            'classification' => ['Entity', 'Field', 'Protection', 'Purpose', 'Retention'],
            'protection' => ['Entity', 'Field', 'Classification', 'Purpose', 'Retention'],
            'purpose' => ['Entity', 'Field', 'Classification', 'Protection', 'Retention'],
            default => ['Entity', 'Field', 'Classification', 'Protection', 'Purpose', 'Retention'],
        };
    }

    /**
     * Formats table rows based on grouping.
     */
    private function formatTableRows(array $fields, string $groupBy): array
    {
        return array_map(function (array $field) use ($groupBy): array {
            $purpose = $field['purpose'] ?? '-';
            $retention = $field['retention'] ?? '-';

            return match ($groupBy) {
                'entity' => [$field['field'], $field['classification'], $field['protection'], $purpose, $retention],
                'classification' => [$field['entity'], $field['field'], $field['protection'], $purpose, $retention],
                'protection' => [$field['entity'], $field['field'], $field['classification'], $purpose, $retention],
                'purpose' => [$field['entity'], $field['field'], $field['classification'], $field['protection'], $retention],
                default => [$field['entity'], $field['field'], $field['classification'], $field['protection'], $purpose, $retention],
            };
        }, $fields);
    }

    /**
     * Displays a summary of sensitive fields.
     */
    private function displaySummary(array $fields): void
    {
        $byClassification = [];
        $byProtection = [];

        foreach ($fields as $field) {
            $byClassification[$field['classification']] = ($byClassification[$field['classification']] ?? 0) + 1;
            $byProtection[$field['protection']] = ($byProtection[$field['protection']] ?? 0) + 1;
        }

        $this->io->section('Summary');

        $this->io->text('By Classification:');
        foreach ($byClassification as $class => $count) {
            $this->io->text(sprintf('  • %s: %d', $class, $count));
        }

        $this->io->newLine();
        $this->io->text('By Protection:');
        foreach ($byProtection as $prot => $count) {
            $this->io->text(sprintf('  • %s: %d', $prot, $count));
        }

        $this->io->newLine();
        $this->io->text(sprintf('Total: %d sensitive fields', count($fields)));
    }

    /**
     * Displays additional information in verbose mode.
     */
    private function displayVerboseOutput(array $fields, Stopwatch $stopwatch, OutputInterface $output): void
    {
        if (!$output->isVerbose()) {
            return;
        }

        $event = $stopwatch->stop('sensitive-list-command');
        $this->io->section('Performance Metrics');
        $this->io->table(
            ['Metric', 'Value'],
            [
                ['Total Fields', count($fields)],
                ['Entities Scanned', count($this->entityManager->getMetadataFactory()->getAllMetadata())],
                ['Elapsed Time', sprintf('%.2f ms', $event->getDuration())],
                ['Memory Used', sprintf('%.2f MB', $event->getMemory() / (1024 ** 2))],
            ]
        );
    }
}
