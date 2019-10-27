<?php

use Zeigo\Illuminate\CacheDatabase\Contracts\CacheForever;
use Zeigo\Illuminate\CacheDatabase\Contracts\RedisHashRepository as RedisHashRepositoryInterface;

class RedisHashRepository implements CacheForever, RedisHashRepositoryInterface
{
}
