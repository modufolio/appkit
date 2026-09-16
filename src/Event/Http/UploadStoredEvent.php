<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Event\Http;

/**
 * An uploaded file was validated and written to disk.
 *
 * Dispatched by {@see \Modufolio\Appkit\Http\Upload::saveTo()} once the
 * file is at its final path — the moment a virus scan, a thumbnail job or an
 * index entry can be queued against it.
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class UploadStoredEvent
{
    /**
     * @param string      $path           the absolute path the file was written to
     * @param string      $filename       the name it was stored under, after sanitising
     * @param string|null $clientFilename the name the browser sent, as sent
     * @param int|null    $size           bytes, when the stream reported one
     * @param string|null $mimeType       the type sniffed from the content, not the client's claim
     */
    public function __construct(
        public string $path,
        public string $filename,
        public ?string $clientFilename,
        public ?int $size,
        public ?string $mimeType,
    ) {
    }
}
