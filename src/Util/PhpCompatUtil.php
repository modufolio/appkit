<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Util;

use Modufolio\Appkit\Console\FileManager;

/**
 * @author Jesse Rushlow <jr@rushlow.dev>
 *
 * @internal
 */
class PhpCompatUtil
{
    public function __construct(private FileManager $fileManager)
    {
    }

    protected function getPhpVersion(): string
    {
        $rootDirectory = $this->fileManager->getRootDirectory();

        $composerLockPath = \sprintf('%s/composer.lock', $rootDirectory);

        if (!$this->fileManager->fileExists($composerLockPath)) {
            return \PHP_VERSION;
        }

        $lockFileContents = json_decode($this->fileManager->getFileContents($composerLockPath), true);

        if (empty($lockFileContents['platform-overrides']) || empty($lockFileContents['platform-overrides']['php'])) {
            return \PHP_VERSION;
        }

        return $lockFileContents['platform-overrides']['php'];
    }
}
