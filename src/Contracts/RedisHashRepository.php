<?php

namespace Zeigo\Illuminate\CacheDatabase\Contracts;

interface RedisHashRepository
{
    /**
     * Get version of latest modified, the cached data will be marked with that.
     *
     * @return string
     */
    public function version(): string;

    /**
     * Fetch data from original storage by multiple ids.
     *
     * @param array $ids
     * @param string|null $group
     * @return array
     */
    public function fetch(array $ids, string $group = null): array;

    /**
     * TTL (seconds).
     *
     * @return int
     */
    public function ttl(): int;
}
