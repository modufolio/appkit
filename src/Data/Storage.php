<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Data;

use Modufolio\Appkit\Toolkit\A;

/**
 * Key-value store backed by a PHP file (`<?php return [...];`).
 *
 * The file is executed on read, so this is for configuration and application
 * state the code controls — never for untrusted or unvalidated input. An
 * object is written as a `__set_state()` call that runs on load, and anything
 * that can influence the path or the contents is code execution. Put
 * user-submitted data in the database or in a JSON file via Data::write().
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Storage
{
    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * A file that does not exist yet is an empty store; save() creates it,
     * parent directories included.
     */
    public function __construct(public string $filePath)
    {
        $this->data = is_file($this->filePath) ? PHP::read($this->filePath) : [];
    }

    /**
     * @param string|array<string, mixed> $key
     */
    public function insert(string|array $key, mixed $value = null): Storage
    {
        if (!is_array($key)) {
            $this->data[$key] = $value;

            return $this;
        }

        $this->data = array_merge($this->data, $key);

        return $this;
    }

    /**
     * @throws \Exception
     */
    public function save(): Storage
    {
        PHP::write($this->filePath, $this->data);

        return $this;
    }

    /**
     * @param string|list<string>|null $key
     */
    public function get(array|string|null $key = null, mixed $default = null): mixed
    {
        if (null === $key) {
            return $this->data;
        }

        $col = is_string($key) ? array_column($this->data, $key) : [];
        if (count($col) > 0) {
            return $col;
        }

        return A::get($this->data, $key, $default);
    }

    /**
     * Removes an item from the data array.
     *
     * @return array<string, mixed>
     */
    public function remove(?string $key = null): array
    {
        // reset the entire array
        if (null === $key) {
            return $this->data = [];
        }

        // unset a single key
        unset($this->data[$key]);

        // return the array without the removed key
        return $this->data;
    }
}
