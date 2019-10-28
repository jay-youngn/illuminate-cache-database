<?php

namespace Zeigo\Illuminate\CacheDatabase\Facades;

use Illuminate\Support\Facades\Facade;
use Zeigo\Illuminate\CacheDatabase\Processor\RedisHash;

/**
 * @method static void register(string $table, $class)
 * @method static $this fill(array $repositories)
 * @method static array getRepositories()
 * @method static bool isRegistered(string $table)
 * @method static $this from(string $group, string $table)
 * @method static $this table(string $table)
 * @method static array|null find($id, array $default = null)
 * @method static array get(array $ids)
 * @method static array all()
 * @method static void delete($ids)
 * @method static void clear()
 * @method static void clearForeverTag()
 *
 * @see \Zeigo\Illuminate\CacheDatabase\Processor\RedisHash
 */
class RedisHashQuery extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RedisHash::class;
    }
}
