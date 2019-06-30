<?php

namespace Zeigo\Illuminate\CacheDatabase\Contracts;

interface RedisHashRepository
{
    /**
     * Revised version.
     *
     * @return  string
     */
    public function version(): string;

    /**
     * Get data.
     *
     * @param   array  $ids
     * @param   string|null  $group
     * @return  array
     */
    public function fetch(array $ids, string $group = null): array;

    /**
     * TTL (seconds).
     *
     * @return  int
     */
    public function ttl(): int;
}
