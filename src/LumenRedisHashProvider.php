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

    /**
     * {@inheritdoc}
     */
    protected function versionCompare(string $compareVersion, string $operator)
    {
        // Lumen (5.8.12) (Laravel Components 5.8.*)
        $lumenVersion = $this->app->version();

        if (preg_match('/Lumen \((\d\.\d\.\d{1,2})\)( \(Laravel Components (\d\.\d\.\*)\))?/', $lumenVersion, $matches)) {
            // Prefer Laravel Components version.
            $lumenVersion = isset($matches[3]) ? $matches[3] : $matches[1];
        }

        return version_compare($lumenVersion, $compareVersion, $operator);
    }
}
