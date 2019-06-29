<?php

namespace Zeigo\Illuminate\CacheDatabase;

use Illuminate\Support\ServiceProvider;
use Zeigo\Illuminate\CacheDatabase\Processor\RedisHash;

class RedisHashProvider extends ServiceProvider
{
    /**
     * Defer load.
     *
     * @var  boolean
     */
    protected $defer = true;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/Resources/hash-database.config.php' => config_path('hash-database.php'),
        ], 'config');
    }

    /**
     * Register the application services.
     *
     * @return void
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
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            RedisHash::class,
        ];
    }
}
