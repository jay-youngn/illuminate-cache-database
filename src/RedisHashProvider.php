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

        $this->mergeConfigFrom(__DIR__ . '/Resources/hash-database.php', 'hash-database');
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->app->singleton(RedisHash::class, function($app) {
            $config = $app['config']['hash-database'];

            if ($this->versionCompare('5.4', '>=')) {
                $predisClient = $app['redis']->connection($config['connection'])->client();
            } else {
                $predisClient = $app['redis']->connection($config['connection']);
            }

            return (new RedisHash(
                $predisClient,
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

    /**
     * Compare illuminate component version.
     *     - illuminate/redis 5.4 has a big upgrade.
     *
     * @param string $compareVersion
     * @param string $operator
     * @return bool|null
     */
    protected function versionCompare(string $compareVersion, string $operator)
    {
        return version_compare($this->app->version(), $compareVersion, $operator);
    }
}
