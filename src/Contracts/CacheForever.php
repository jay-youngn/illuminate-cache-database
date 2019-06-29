<?php

namespace Zeigo\Illuminate\CacheDatabase\Contracts;

/**
 * Implementing this interface means that such data can be cached permanently.
 */
interface CacheForever
{
    /**
     * Get all data.
     *
     * @param   string  $group
     * @return  array
     */
    public function all(string $group): array;
}
