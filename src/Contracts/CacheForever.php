<?php

namespace Zeigo\Illuminate\CacheDatabase\Contracts;

/**
 * Implementing this interface means that such data can be cached permanently.
 */
interface CacheForever
{
    /**
     * Fetch all data from original storage.
     *
     * @param string|null $group
     * @return array
     */
    public function all(string $group = null): array;
}
