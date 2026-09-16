<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Http;

use Modufolio\Appkit\Event\Http\UploadStoredEvent;
use Modufolio\Appkit\Toolkit\F;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * An uploaded file on its way into the application: a fluent wrapper around
 * a PSR-7 UploadedFileInterface that validates it and stores it safely.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Upload
{
    private UploadedFileInterface $file;

    private ?string $storedFilePath = null;

    /** @var list<string> */
    private array $errors = [];

    private bool $hasErrors = false;

    private ?EventDispatcherInterface $events = null;

    /**
     * Bytes read from an in-memory stream to sniff the MIME type. libmagic only
     * inspects leading bytes, so this caps memory use for streamed uploads.
     */
    private const MIME_SNIFF_BYTES = 65_536;

    /**
     * Extensions that must never be written by saveTo() unless the caller opts
     * out explicitly. These are server-executable or config files that lead to
     * remote code execution when dropped in a web-served directory. The check is
     * a last-line default; content-type validation (isImage/hasMimeType) is still
     * the primary defense.
     */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx',
        'htaccess', 'htpasswd', 'ini', 'exe', 'com', 'bat', 'cmd', 'dll', 'so',
        // Served inline, these run script in the visitor's browser under the
        // application's origin — the same stored-XSS reason svg is listed.
        'svg', 'html', 'htm', 'xhtml', 'xht',
    ];

    /**
     * Create a new file wrapper.
     */
    public function __construct(UploadedFileInterface $file)
    {
        $this->file = $file;

        // Check for upload errors immediately
        if (UPLOAD_ERR_OK !== $this->file->getError()) {
            $this->addError($this->translateError($this->file->getError()));
        }
    }

    /**
     * Factory method to create a new wrapper instance.
     *
     * @param EventDispatcherInterface|null $events told when saveTo() has stored the file — pass
     *                                              the application's dispatcher to queue a scan
     *                                              or a thumbnail job off {@see UploadStoredEvent}
     */
    public static function from(UploadedFileInterface $file, ?EventDispatcherInterface $events = null): self
    {
        $upload = new self($file);
        $upload->events = $events;

        return $upload;
    }

    /**
     * Dispatch {@see UploadStoredEvent} through this dispatcher once the file is saved.
     */
    public function notifying(EventDispatcherInterface $events): self
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Assert the file has a specific extension.
     *
     * @param string|list<string> $extension Extension or array of extensions
     * @param string|null         $message   Custom error message
     */
    public function hasExtension($extension, ?string $message = null): self
    {
        $extensions = is_array($extension) ? $extension : [$extension];
        $fileExtension = pathinfo($this->file->getClientFilename() ?? '', PATHINFO_EXTENSION);

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
     * @param string|list<string> $mimeType Mime type or array of mime types
     * @param string|null         $message  Custom error message
     */
    public function hasMimeType($mimeType, ?string $message = null): self
    {
        $mimeTypes = is_array($mimeType) ? $mimeType : [$mimeType];

        $actualMimeType = $this->detectMimeType();

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
     * Assert the file is a raster image.
     *
     * SVG is intentionally excluded: it can carry embedded scripts and is a
     * stored-XSS risk when served inline. Allow it explicitly with
     * hasMimeType('image/svg+xml') only after sanitising the markup.
     *
     * @param string|null $message Custom error message
     */
    public function isImage(?string $message = null): self
    {
        return $this->hasMimeType([
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], $message ?? 'File must be an image.');
    }

    /**
     * Assert the file size is less than or equal to a maximum size in bytes.
     *
     * @param int         $size    Maximum size in bytes
     * @param string|null $message Custom error message
     */
    public function maxSize(int $size, ?string $message = null): self
    {
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
     * @param int         $size    Minimum size in bytes
     * @param string|null $message Custom error message
     */
    public function minSize(int $size, ?string $message = null): self
    {
        if ($this->file->getSize() < $size) {
            $this->addError($message ?? sprintf(
                'File size must be at least %s. Got: %s.',
                $this->formatBytes($size),
                $this->formatBytes($this->file->getSize() ?? 0)
            ));
        }

        return $this;
    }

    /**
     * Assert the filename matches a specific pattern.
     *
     * @param string      $pattern Regular expression pattern
     * @param string|null $message Custom error message
     */
    public function matchesFilenamePattern(string $pattern, ?string $message = null): self
    {
        $clientFilename = $this->file->getClientFilename() ?? '';

        if (!preg_match($pattern, $clientFilename)) {
            $this->addError($message ?? sprintf(
                'Filename does not match the required pattern. Got: %s.',
                $clientFilename
            ));
        }

        return $this;
    }

    /**
     * Assert the file passes a custom validation.
     *
     * @param callable $validator Function that returns true if valid, false otherwise
     * @param string   $message   Error message
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
     * By default this refuses to write server-executable extensions (see
     * DANGEROUS_EXTENSIONS) even if no explicit validator was chained, so a
     * forgotten isImage()/hasExtension() call cannot silently drop a .php file
     * into a web-served directory. Pass $allowUnsafeExtension = true only when
     * you fully control and trust the target directory.
     *
     * It also never overwrites an existing file: pass an explicit unique
     * $filename (e.g. derived from a random token or DB id) for user uploads.
     *
     * @param string      $path                 Where to save the file
     * @param string|null $filename             Optional filename (defaults to the original name)
     * @param bool        $allowUnsafeExtension Opt out of the executable-extension denylist
     *
     * @throws \InvalidArgumentException If validation fails, the extension is unsafe, or the target exists
     */
    public function saveTo(string $path, ?string $filename = null, bool $allowUnsafeExtension = false): self
    {
        if ($this->hasErrors) {
            throw new \InvalidArgumentException('Cannot save file due to validation errors: '.implode(', ', $this->errors));
        }

        $filename = F::safeName($filename ?? $this->file->getClientFilename() ?? '');

        if ('' === $filename) {
            throw new \InvalidArgumentException('Cannot save file: resolved filename is empty.');
        }

        // Every dotted segment after the first counts, not just the last:
        // Apache's mod_mime maps each of `shell.php.jpg`'s extensions
        // independently, so that file is served as PHP with a .jpg name.
        if (!$allowUnsafeExtension && null !== ($extension = self::dangerousExtension($filename))) {
            throw new \InvalidArgumentException(sprintf('Refusing to save file with a potentially executable extension ".%s". Validate the upload (e.g. isImage()) and store it under a safe extension.', $extension));
        }

        $fullPath = rtrim($path, '/').'/'.$filename;

        if (file_exists($fullPath)) {
            throw new \InvalidArgumentException(sprintf('Refusing to overwrite existing file: %s', $fullPath));
        }

        // Create directory if it doesn't exist
        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create upload directory: %s', $path));
        }

        $this->file->moveTo($fullPath);

        $this->storedFilePath = $fullPath;

        // The file is on disk: whatever listens (a scan, a thumbnail job, an
        // index) now has a path to work with. The type is sniffed from that
        // file — the upload stream is gone once moved.
        if (null !== $this->events) {
            $mimeType = (new \finfo(\FILEINFO_MIME_TYPE))->file($fullPath);

            $this->events->dispatch(new UploadStoredEvent(
                path: $fullPath,
                filename: $filename,
                clientFilename: $this->file->getClientFilename(),
                size: $this->file->getSize(),
                mimeType: false === $mimeType ? null : $mimeType,
            ));
        }

        return $this;
    }

    /**
     * The first extension segment of $filename on the denylist, or null when
     * none is. `archive.tar.gz` yields null; `shell.php.jpg` yields `php`.
     */
    private static function dangerousExtension(string $filename): ?string
    {
        $segments = explode('.', strtolower($filename));
        array_shift($segments);

        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * Get the underlying PSR-7 UploadedFileInterface.
     */
    public function getFile(): UploadedFileInterface
    {
        return $this->file;
    }

    /**
     * Check if the file has validation errors.
     */
    public function hasErrors(): bool
    {
        return $this->hasErrors;
    }

    /**
     * Get all validation errors.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Detect the file's MIME type without loading the whole upload into memory.
     *
     * Uses the on-disk temp file when available (libmagic reads bounded bytes);
     * otherwise sniffs a capped buffer of leading bytes from the stream.
     */
    private function detectMimeType(): ?string
    {
        $stream = $this->file->getStream();
        $uri = $stream->getMetadata('uri');

        if (is_string($uri) && !str_starts_with($uri, 'php://') && is_readable($uri)) {
            return mime_content_type($uri) ?: null;
        }

        $stream->rewind();
        $head = $stream->read(self::MIME_SNIFF_BYTES);
        $stream->rewind();

        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: null;
    }

    /**
     * Add an error message to the errors list.
     *
     * @param string $message Error message
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
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = (int) min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }

    public function getStoredFilePath(): ?string
    {
        return $this->storedFilePath;
    }

    /**
     * Translates a PHP file upload error code to a human-readable message.
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
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}
