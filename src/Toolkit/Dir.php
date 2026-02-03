<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Toolkit;

use Exception;
use Throwable;

/**
 * The `Dir` class provides methods
 * for dealing with directories on the
 * file system level, like creating,
 * listing, moving, copying or
 * evaluating directories etc.
 *
 * @package   Kirby Toolkit
 * @author    Bastian Allgeier <bastian@getkirby.com>
 * @link      https://getkirby.com
 * @copyright Bastian Allgeier GmbH
 * @license   https://opensource.org/licenses/MIT
 */
class Dir
{
    /**
     * Ignore when scanning directories
     */
    public static array $ignore = [
        '.',
        '..',
        '.DS_Store',
        '.gitignore',
        '.git',
        '.svn',
        '.htaccess',
        'Thumb.db',
        '@eaDir'
    ];

    public static string $numSeparator = '_';

    /**
     * Copy the directory to a new destination
     *
     * @param array|false $ignore List of full paths to skip during copying
     *                            or `false` to copy all files, including
     *                            those listed in `Dir::$ignore`
     */
    public static function copy(
        string $dir,
        string $target,
        bool $recursive = true,
        array|false $ignore = []
    ): bool {
        if (is_dir($dir) === false) {
            throw new \RuntimeException('The directory "' . $dir . '" does not exist');
        }

        if (is_dir($target) === true) {
            throw new \RuntimeException('The target directory "' . $target . '" exists');
        }

        if (static::make($target) !== true) {
            throw new \RuntimeException('The target directory "' . $target . '" could not be created');
        }

        foreach (static::read($dir, $ignore === false ? [] : null) as $name) {
            $root = $dir . '/' . $name;

            if (
                is_array($ignore) === true &&
                in_array($root, $ignore) === true
            ) {
                continue;
            }

            if (is_dir($root) === true) {
                if ($recursive === true) {
                    static::copy($root, $target . '/' . $name, true, $ignore);
                }
            } else {
                F::copy($root, $target . '/' . $name);
            }
        }

        return true;
    }

    /**
     * Get all subdirectories
     */
    public static function dirs(
        string $dir,
        array|null $ignore = null,
        bool $absolute = false
    ): array {
        $scan   = static::read($dir, $ignore, true);
        $result = array_values(array_filter($scan, 'is_dir'));

        if ($absolute !== true) {
            $result = array_map('basename', $result);
        }

        return $result;
    }

    /**
     * Checks if the directory exists on disk
     */
    public static function exists(string $dir): bool
    {
        return is_dir($dir) === true;
    }

    /**
     * Get all files
     */
    public static function files(
        string $dir,
        array|null $ignore = null,
        bool $absolute = false
    ): array {
        $scan   = static::read($dir, $ignore, true);
        $result = array_values(array_filter($scan, 'is_file'));

        if ($absolute !== true) {
            $result = array_map('basename', $result);
        }

        return $result;
    }

    /**
     * Read the directory and all subdirectories
     *
     * @todo Remove support for `$ignore = null` in a major release
     * @param array|false|null $ignore Array of absolut file paths;
     *                                 `false` to disable `Dir::$ignore` list
     *                                 (passing null is deprecated)
     */
    public static function index(
        string $dir,
        bool $recursive = false,
        array|false|null $ignore = [],
        string|null $path = null
    ): array {
        $result = [];
        $dir    = realpath($dir);
        $items  = static::read($dir, $ignore === false ? [] : null);

        foreach ($items as $item) {
            $root = $dir . '/' . $item;

            if (
                is_array($ignore) === true &&
                in_array($root, $ignore) === true
            ) {
                continue;
            }

            $entry    = $path !== null ? $path . '/' . $item : $item;
            $result[] = $entry;

            if ($recursive === true && is_dir($root) === true) {
                $result = array_merge($result, static::index($root, true, $ignore, $entry));
            }
        }

        return $result;
    }

    /**
     * Checks if the folder has any contents
     */
    public static function isEmpty(string $dir): bool
    {
        return count(static::read($dir)) === 0;
    }

    /**
     * Checks if the directory is readable
     */
    public static function isReadable(string $dir): bool
    {
        return is_readable($dir);
    }

    /**
     * Checks if the directory is writable
     */
    public static function isWritable(string $dir): bool
    {
        return is_writable($dir);
    }


    /**
     * Create a (symbolic) link to a directory
     * @throws Exception
     */
    public static function link(string $source, string $link): bool
    {
        static::make(dirname($link), true);

        if (is_dir($link) === true) {
            return true;
        }

        if (is_dir($source) === false) {
            throw new \RuntimeException(sprintf('The directory "%s" does not exist and cannot be linked', $source));
        }

        try {
            return symlink($source, $link) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Creates a new directory
     *
     * @param string $dir The path for the new directory
     * @param bool $recursive Create all parent directories, which don't exist
     * @return bool True: the dir has been created, false: creating failed
     * @throws \Exception If a file with the provided path already exists or the parent directory is not writable
     */
    public static function make(string $dir, bool $recursive = true): bool
    {
        if (empty($dir)) {
            return false;
        }

        if (is_dir($dir)) {
            return true;
        }

        if (is_file($dir)) {
            throw new \RuntimeException(sprintf('A file with the name "%s" already exists', $dir));
        }

        $parent = dirname($dir);

        if ($recursive && !is_dir($parent)) {
            static::make($parent, true);
        }

        if (!is_writable($parent)) {
            throw new \RuntimeException(sprintf('The directory "%s" cannot be created', $dir));
        }

        try {
            return mkdir($dir);
        } catch (Exception $e) {
            // Check if the error message indicates that the directory was already created (race condition)
            if (Str::endsWith($e->getMessage(), 'File exists')) {
                // Consider it a success
                return true;
            }

            // Re-throw the exception if it's not the expected error
            throw $e;
        }
    }

    /**
     * Recursively check when the dir and all
     * subfolders have been modified for the last time.
     *
     * @param string $dir The path of the directory
     * @param string|null $format
     * @param string|null $handler
     * @return int|string
     */
    public static function modified(string $dir, string|null $format = null, string|null $handler = null): int|string
    {
        $modified = filemtime($dir);
        $items    = static::read($dir);

        foreach ($items as $item) {
            if (is_file($dir . '/' . $item) === true) {
                $newModified = filemtime($dir . '/' . $item);
            } else {
                $newModified = static::modified($dir . '/' . $item);
            }

            $modified = ($newModified > $modified) ? $newModified : $modified;
        }

        return Str::date($modified, $format, $handler);
    }

    /**
     * Moves a directory to a new location
     *
     * @param string $old The current path of the directory
     * @param string $new The desired path where the dir should be moved to
     * @return bool true: the directory has been moved, false: moving failed
     */
    public static function move(string $old, string $new): bool
    {
        if ($old === $new) {
            return true;
        }

        if (is_dir($old) === false || is_dir($new) === true) {
            return false;
        }

        if (static::make(dirname($new), true) !== true) {
            throw new \RuntimeException('The parent directory cannot be created');
        }

        return rename($old, $new);
    }

    /**
     * Returns a nicely formatted size of all the contents of the folder
     *
     * @param string $dir The path of the directory
     * @param string|false|null $locale Locale for number formatting,
     *                                  `null` for the current locale,
     *                                  `false` to disable number formatting
     */
    public static function niceSize(
        string $dir,
        string|false|null $locale = null
    ): string {
        return F::niceSize(static::size($dir), $locale);
    }

    /**
     * Reads all files from a directory and returns them as an array.
     * It skips unwanted invisible stuff.
     *
     * @param string $dir The path of directory
     * @param array $ignore Optional array with filenames, which should be ignored
     * @param bool $absolute If true, the full path for each item will be returned
     * @return array An array of filenames
     */
    public static function read(
        string $dir,
        array|null $ignore = null,
        bool $absolute = false
    ): array {
        if (is_dir($dir) === false) {
            return [];
        }

        // create the ignore pattern
        $ignore ??= static::$ignore;
        $ignore   = array_merge($ignore, ['.', '..']);

        // scan for all files and dirs
        $result = array_values((array)array_diff(scandir($dir), $ignore));

        // add absolute paths
        if ($absolute === true) {
            $result = array_map(static fn ($item) => $dir . '/' . $item, $result);
        }

        return $result;
    }

    /**
     * Removes a folder including all containing files and folders
     */
    public static function remove(string $dir): bool
    {
        $dir = realpath($dir);

        if ($dir === false) {
            return true;
        }


        if (is_dir($dir) === false) {
            return true;
        }

        if (is_link($dir) === true) {
            return F::unlink($dir);
        }

        foreach (scandir($dir) as $childName) {
            if (in_array($childName, ['.', '..']) === true) {
                continue;
            }

            $child = $dir . '/' . $childName;

            if (is_dir($child) === true && is_link($child) === false) {
                static::remove($child);
            } else {
                F::unlink($child);
            }
        }

        return rmdir($dir);
    }

    /**
     * Gets the size of the directory
     *
     * @param string $dir The path of the directory
     * @param bool $recursive Include all subfolders and their files
     */
    public static function size(string $dir, bool $recursive = true): int|false
    {
        if (is_dir($dir) === false) {
            return false;
        }

        // Get size for all direct files
        $size = F::size(static::files($dir, null, true));

        // if recursive, add sizes of all subdirectories
        if ($recursive === true) {
            foreach (static::dirs($dir, null, true) as $subdir) {
                $size += static::size($subdir);
            }
        }

        return $size;
    }

    /**
     * Checks if the directory or any subdirectory has been
     * modified after the given timestamp
     */
    public static function wasModifiedAfter(string $dir, int $time): bool
    {
        if (filemtime($dir) > $time) {
            return true;
        }

        $content = static::read($dir);

        foreach ($content as $item) {
            $subdir = $dir . '/' . $item;

            if (filemtime($subdir) > $time) {
                return true;
            }

            if (is_dir($subdir) === true && static::wasModifiedAfter($subdir, $time) === true) {
                return true;
            }
        }

        return false;
    }
}
