<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Image;

use Modufolio\Appkit\Data\Data;

/**
 * JSON file-based storage for image transformation jobs
 *
 * @package   Image
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class JsonJobStorage implements JobStorageInterface
{
    private string $jobsSubdir;

    public function __construct(string $jobsSubdir = '.jobs')
    {
        $this->jobsSubdir = ltrim($jobsSubdir, '/');
    }

    public function saveJob(
        string $mediaRoot,
        string $thumbName,
        array $options
    ): void {
        $jobFile = $this->getJobPath($mediaRoot, $thumbName);

        try {
            Data::write($jobFile, $options);
        } catch (\Throwable) {
            // Silently fail - thumbnail will be generated on-demand
        }
    }

    public function loadJob(string $mediaRoot, string $thumbName): array|null
    {
        $jobFile = $this->getJobPath($mediaRoot, $thumbName);

        try {
            if (!file_exists($jobFile)) {
                return null;
            }

            $content = file_get_contents($jobFile);
            $data = json_decode($content, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function deleteJob(string $mediaRoot, string $thumbName): bool
    {
        $jobFile = $this->getJobPath($mediaRoot, $thumbName);

        try {
            if (file_exists($jobFile)) {
                return unlink($jobFile);
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function jobExists(string $mediaRoot, string $thumbName): bool
    {
        return file_exists($this->getJobPath($mediaRoot, $thumbName));
    }

    /**
     * Get the full path to a job file
     */
    private function getJobPath(string $mediaRoot, string $thumbName): string
    {
        $jobsDir = rtrim($mediaRoot, '/') . '/' . $this->jobsSubdir;
        return $jobsDir . '/' . basename($thumbName) . '.json';
    }
}
