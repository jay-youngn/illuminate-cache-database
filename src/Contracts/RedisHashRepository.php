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
     * @param   string  $group
     * @param   array  $ids
     * @return  array
     */
    public function fetch(string $group, array $ids): array;

    /**
     * TTL (minutes).
     *
     * @return  int
     */
    public function ttl(): int;
}
