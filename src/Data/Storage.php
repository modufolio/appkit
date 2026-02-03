<?php

declare(strict_types = 1);

namespace Modufolio\Appkit\Data;

use Modufolio\Appkit\Toolkit\A;
use Exception;

/**
 * Dispatch
 *
 * @package   Appkit Data
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class Storage
{
    public array $data = [];

    public function __construct(public string $filePath)
    {
        $this->data = PHP::read($this->filePath);
    }

    public function insert($key, $value = null): Storage
    {
        if (!is_array($key)) {
            $this->data[$key] = $value;
            return $this;
        }

        $this->data = array_merge($this->data, $key);
        return $this;
    }

    /**
     * @throws Exception
     */
    public function save(): Storage
    {
        PHP::write($this->filePath, $this->data);
        return $this;
    }



    /**
     * @param array|string|null $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(array|string|null $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        $col = array_column($this->data, $key);
        if (count($col) > 0) {
            return $col;
        }

        return A::get($this->data, $key, $default);
    }

    /**
     * Removes an item from the data array
     *
     * @param string|null $key
     * @return array
     */
    public function remove(?string $key = null): array
    {
        // reset the entire array
        if ($key === null) {
            return $this->data = [];
        }

        // unset a single key
        unset($this->data[$key]);

        // return the array without the removed key
        return $this->data;
    }
}
