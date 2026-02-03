<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Util;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

final class MakerFileLinkFormatter
{
    private ?string $fileLinkFormat;

    private const IDE_LINK_FORMATS = [
        'textmate' => 'txmt://open?url=file://%f&line=%l',
        'macvim' => 'mvim://open?url=file://%f&line=%l',
        'emacs' => 'emacs://open?url=file://%f&line=%l',
        'sublime' => 'subl://open?url=file://%f&line=%l',
        'phpstorm' => 'phpstorm://open?file=%f&line=%l',
        'atom' => 'atom://core/open/file?filename=%f&line=%l',
        'vscode' => 'vscode://file/%f:%l',
    ];

    public function __construct(?string $fileLinkFormat = null)
    {
        if ($fileLinkFormat === null || $fileLinkFormat === '') {
            $this->fileLinkFormat = 'file://%f#L%l';
        } else {
            $this->fileLinkFormat = self::IDE_LINK_FORMATS[$fileLinkFormat] ?? $fileLinkFormat;
        }
    }

    public function makeLinkedPath(string $absolutePath, string $relativePath): string
    {
        // workaround for difficulties parsing linked file paths in appveyor
        if (getenv('MAKER_DISABLE_FILE_LINKS')) {
            return $relativePath;
        }

        $url = $this->formatUrl($absolutePath, 1);
        if (!$url) {
            return $relativePath;
        }

        // Always use manual ANSI hyperlink creation since OutputFormatterStyle::setHref
        // might not exist or might not work as expected
        return $this->createHyperlink($url, $relativePath);
    }

    private function formatUrl(string $file, int $line): string
    {
        return strtr($this->fileLinkFormat, ['%f' => $file, '%l' => $line]);
    }

    private function createHyperlink(string $url, string $text): string
    {
        // ANSI hyperlink format: \033]8;;URL\033\\TEXT\033]8;;\033\\
        return "\033]8;;{$url}\033\\{$text}\033]8;;\033\\";
    }
}
