<?php

namespace Zeigo\Illuminate\CacheDatabase;

class LumenRedisHashProvider extends RedisHashProvider
{
    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        $this->app->configure('hash-database');
        $this->mergeConfigFrom(__DIR__ . '/Resources/hash-database.php', 'hash-database');
    }
}
