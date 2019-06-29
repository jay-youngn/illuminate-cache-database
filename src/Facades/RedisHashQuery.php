<?php

namespace Zeigo\Illuminate\CacheDatabase\Facades;

use Illuminate\Support\Facades\Facade;
use Zeigo\Illuminate\CacheDatabase\Processor\RedisHash;

/**
 * @see Zeigo\Illuminate\CacheDatabase\Processor\RedisHash
 */
class RedisHashQuery extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RedisHash::class;
    }
}
