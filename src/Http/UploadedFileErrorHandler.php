<?php

namespace Modufolio\Appkit\Http;

use InvalidArgumentException;
use Modufolio\Appkit\Toolkit\F;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A fluent wrapper around PSR-7 UploadedFileInterface that provides validation
 * and error handling capabilities.
 */
class UploadedFileErrorHandler
{
    private UploadedFileInterface $file;

    private ?string $storedFilePath = null;

    private array $errors = [];

    private bool $hasErrors = false;

    private array $allowedMimeTypes = [];

    /** @var int|null */
    private $maxSize = null;

    /** @var int|null */
    private $minSize = null;

    /**
     * Create a new file wrapper.
     *
     * @param UploadedFileInterface $file
     */
    public function __construct(UploadedFileInterface $file)
    {
        $this->file = $file;

        // Check for upload errors immediately
        if ($this->file->getError() !== UPLOAD_ERR_OK) {
            $this->addError($this->translateError($this->file->getError()));
        }
    }

    /**
     * Factory method to create a new wrapper instance.
     *
     * @param UploadedFileInterface $file
     * @return self
     */
    public static function from(UploadedFileInterface $file): self
    {
        return new self($file);
    }

    /**
     * Assert the file has a specific extension.
     *
     * @param string|array $extension Extension or array of extensions
     * @param string|null $message Custom error message
     * @return self
     */
    public function hasExtension($extension, ?string $message = null): self
    {
        $extensions = is_array($extension) ? $extension : [$extension];
        $fileExtension = pathinfo($this->file->getClientFilename(), PATHINFO_EXTENSION);

        if (!in_array(strtolower($fileExtension), array_map('strtolower', $extensions))) {
            $this->addError($message ?? sprintf(
                'File must have one of the following extensions: %s. Got: %s.',
                implode(', ', $extensions),
                $fileExtension
            ));
        }

        return $this;
    }

    /**
     * Assert the file has a specific mime type.
     *
     * @param string|array $mimeType Mime type or array of mime types
     * @param string|null $message Custom error message
     * @return self
     */
    public function hasMimeType($mimeType, ?string $message = null): self
    {
        $mimeTypes = is_array($mimeType) ? $mimeType : [$mimeType];
        $this->allowedMimeTypes = array_merge($this->allowedMimeTypes, $mimeTypes);

        // Store the file temporarily to check its mime type
        $tmpFile = $this->file->getStream()->getMetadata('uri');

        if (!$tmpFile) {
            // If we can't get the URI, we'll need to save it temporarily
            $tmpFile = tempnam(sys_get_temp_dir(), 'upload_check_');
            file_put_contents($tmpFile, $this->file->getStream()->getContents());

            // Reset stream position
            $this->file->getStream()->rewind();
        }

        $actualMimeType = mime_content_type($tmpFile);

        // Clean up if we created a temporary file
        if (str_contains($tmpFile, 'upload_check_')) {
            @unlink($tmpFile);
        }

        if (!in_array($actualMimeType, $mimeTypes)) {
            $this->addError($message ?? sprintf(
                'File must be one of the following types: %s. Got: %s.',
                implode(', ', $mimeTypes),
                $actualMimeType
            ));
        }

        return $this;
    }

    /**
     * Assert the file is an image.
     *
     * @param string|null $message Custom error message
     * @return self
     */
    public function isImage(?string $message = null): self
    {
        return $this->hasMimeType([
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml'
        ], $message ?? 'File must be an image.');
    }

    /**
     * Assert the file size is less than or equal to a maximum size in bytes.
     *
     * @param int $size Maximum size in bytes
     * @param string|null $message Custom error message
     * @return self
     */
    public function maxSize(int $size, ?string $message = null): self
    {
        $this->maxSize = $size;

        if ($this->file->getSize() > $size) {
            $this->addError($message ?? sprintf(
                'File size must not exceed %s. Got: %s.',
                $this->formatBytes($size),
                $this->formatBytes($this->file->getSize())
            ));
        }

        return $this;
    }

    /**
     * Assert the file size is greater than or equal to a minimum size in bytes.
     *
     * @param int $size Minimum size in bytes
     * @param string|null $message Custom error message
     * @return self
     */
    public function minSize(int $size, ?string $message = null): self
    {
        $this->minSize = $size;

        if ($this->file->getSize() < $size) {
            $this->addError($message ?? sprintf(
                'File size must be at least %s. Got: %s.',
                $this->formatBytes($size),
                $this->formatBytes($this->file->getSize())
            ));
        }

        return $this;
    }

    /**
     * Assert the filename matches a specific pattern.
     *
     * @param string $pattern Regular expression pattern
     * @param string|null $message Custom error message
     * @return self
     */
    public function matchesFilenamePattern(string $pattern, ?string $message = null): self
    {
        if (!preg_match($pattern, $this->file->getClientFilename())) {
            $this->addError($message ?? sprintf(
                'Filename must match pattern %s. Got: %s.',
                $pattern,
                $this->file->getClientFilename()
            ));
        }

        return $this;
    }

    /**
     * Assert the file passes a custom validation.
     *
     * @param callable $validator Function that returns true if valid, false otherwise
     * @param string $message Error message
     * @return self
     */
    public function assert(callable $validator, string $message): self
    {
        if (!$validator($this->file)) {
            $this->addError($message);
        }

        return $this;
    }

    /**
     * Save the file to a specific location.
     *
     * @param string $path Where to save the file
     * @param string|null $filename Optional filename (defaults to the original name)
     * @return self
     * @throws InvalidArgumentException If validation fails
     */
    public function saveTo(string $path, ?string $filename = null): self
    {
        if ($this->hasErrors) {
            throw new InvalidArgumentException(
                'Cannot save file due to validation errors: ' . implode(', ', $this->errors)
            );
        }

        $filename = F::safeName($filename ?? $this->file->getClientFilename());
        $fullPath = rtrim($path, '/') . '/' . $filename;

        // Create directory if it doesn't exist
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $this->file->moveTo($fullPath);

        $this->storedFilePath = $fullPath;

        return $this;
    }

    /**
     * Get the underlying PSR-7 UploadedFileInterface.
     *
     * @return UploadedFileInterface
     */
    public function getFile(): UploadedFileInterface
    {
        return $this->file;
    }

    /**
     * Check if the file has validation errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return $this->hasErrors;
    }

    /**
     * Get all validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add an error message to the errors list.
     *
     * @param string $message Error message
     * @return void
     */
    private function addError(string $message): void
    {
        $this->errors[] = $message;
        $this->hasErrors = true;
    }

    /**
     * Format bytes to a human-readable string.
     *
     * @param int $bytes Number of bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function getStoredFilePath(): string
    {
        return $this->storedFilePath;
    }

    /**
     * Translates a PHP file upload error code to a human-readable message.
     *
     * @param int $errorCode
     * @return string
     */
    private function translateError(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'The file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}
