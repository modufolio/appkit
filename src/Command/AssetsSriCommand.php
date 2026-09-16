<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Command;

use Modufolio\Appkit\Template\Asset\FileHashVersioning;
use Modufolio\Appkit\Toolkit\F;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the Subresource Integrity map the templates read.
 *
 * Hashes every CSS and JS file under the given directories of the public
 * root and writes `config/sri.php`, keyed by root-relative path. Run it
 * after every asset build, before deploy; a stale map makes the browser
 * refuse the new files, which is the protection working as designed.
 *
 * Wire it in your console runner with the public directory, the output
 * file, and the directories to scan — the console builds its own
 * dependencies, by design (see docs/console.md).
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[AsCommand(name: 'assets:sri', description: 'Writes the Subresource Integrity map for CSS and JS assets')]
final class AssetsSriCommand extends Command
{
    private const ALGORITHMS = ['sha256', 'sha384', 'sha512'];

    /**
     * @param string       $publicDir   the web root
     * @param string       $outputFile  where the map is written, typically config/sri.php
     * @param list<string> $directories directories under the web root to scan, `assets` by default
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly string $outputFile,
        private readonly array $directories = ['assets'],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('algorithm', 'a', InputOption::VALUE_REQUIRED, 'sha256, sha384 or sha512', 'sha384');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $algorithm = $input->getOption('algorithm');
        if (!\is_string($algorithm) || !\in_array($algorithm, self::ALGORITHMS, true)) {
            $io->error(sprintf('Unsupported algorithm; choose one of %s.', implode(', ', self::ALGORITHMS)));

            return Command::FAILURE;
        }

        $map = $this->hashAssets($algorithm, $io);
        ksort($map);

        $this->write($map);

        $io->success(sprintf('%d asset(s) hashed with %s into %s', \count($map), $algorithm, $this->outputFile));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function hashAssets(string $algorithm, SymfonyStyle $io): array
    {
        $publicDir = rtrim($this->publicDir, '/');
        $map = [];

        foreach ($this->directories as $directory) {
            $dir = $publicDir.'/'.trim($directory, '/');
            if (!is_dir($dir)) {
                $io->warning(sprintf('No such directory under the web root: %s', $directory));
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if (!\in_array(strtolower($file->getExtension()), ['css', 'js'], true)) {
                    continue;
                }
                // A hash-named copy is the same content under another name;
                // the map is keyed by the name templates queue.
                if (null !== FileHashVersioning::unversion($file->getFilename())) {
                    continue;
                }

                $hash = hash_file($algorithm, $file->getPathname(), true);
                if (false === $hash) {
                    $io->warning(sprintf('Could not read %s', $file->getPathname()));
                    continue;
                }

                $path = '/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen($publicDir))), '/');
                $map[$path] = $algorithm.'-'.base64_encode($hash);
                $io->text(sprintf('  <info>%s</info>  %s', $path, $map[$path]));
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function write(array $map): void
    {
        $lines = [];
        foreach ($map as $path => $integrity) {
            $lines[] = '    '.var_export($path, true).' => '.var_export($integrity, true).',';
        }

        $body = [] === $lines ? '' : implode(\PHP_EOL, $lines).\PHP_EOL;

        F::write($this->outputFile, "<?php\n\ndeclare(strict_types=1);\n\n// Written by assets:sri; regenerate after every asset build.\nreturn [\n".$body."];\n");
        F::invalidateOpcodeCache($this->outputFile);
    }
}
