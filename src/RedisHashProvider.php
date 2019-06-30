<?php

namespace Zeigo\Illuminate\CacheDatabase;

use Illuminate\Support\ServiceProvider;
use Zeigo\Illuminate\CacheDatabase\Processor\RedisHash;

class RedisHashProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/Resources/hash-database.php' => config_path('hash-database.php'),
        ], 'config');
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->app->singleton(RedisHash::class, function($app) {
            $config = $app['config']['hash-database'];

            return (new RedisHash(
                $app['redis']->connection($config['connection'])->client(),
                $config['prefix']
            ))->fill($config['repositories']);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function isDeferred()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function provides()
    {
        return [
            RedisHash::class,
        ];
    }
}
