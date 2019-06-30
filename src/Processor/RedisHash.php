<?php

namespace Zeigo\Illuminate\CacheDatabase\Processor;

use Closure;
use BadMethodCallException;
use Illuminate\Support\Arr;
use Predis\ClientInterface;
use Zeigo\Illuminate\CacheDatabase\Contracts\CacheForever;
use Zeigo\Illuminate\CacheDatabase\Contracts\RedisHashRepository;

/**
 * Cache database: Redis hash.
 */
class RedisHash
{
    /**
     * Component version.
     *
     * @var string
     */
    const VERSION = '1.0';

    /**
     * Redis key.
     *
     * @var  string
     */
    private $key;

    /**
     * Data sharding.
     *
     * @var  string
     */
    private $group;

    /**
     * Table name.
     *
     * @var  string
     */
    private $table;

    /**
     * Predis client instance.
     *
     * @var  Predis\ClientInterface
     */
    private static $client;

    /**
     * Redis key prefix.
     *
     * @var  string
     */
    private static $prefix;

    /**
     * The repository bindings.
     *
     * @var array
     */
    private static $repositoryBindings = [];

    /**
     * The repository shared instances.
     *
     * @var array
     */
    private static $repositoryInstances = [];

    /**
     * __construct
     *
     * @param   Predis\ClientInterface  $client
     * @param   string  $prefix
     */
    public function __construct(ClientInterface $client, string $prefix)
    {
        self::$client = $client;
        self::$prefix = $prefix;
    }

    /**
     * Register a repository with the table.
     *
     * @param   string  $table
     * @param   string|Closure  $class
     * @return  void
     */
    public function register(string $table, $class)
    {
        self::$repositoryBindings[$table] = $class;
    }

    /**
     * Mass register repositories.
     *
     * @param   array  $repositories
     * @return  static
     */
    public function fill(array $repositories): self
    {
        self::$repositoryBindings = $repositories;

        return $this;
    }

    /**
     * Get current registered repositories.
     *
     * @param   string  $table
     * @return  array
     */
    public function getRepositories(): array
    {
        return self::$repositoryBindings;
    }

    /**
     * Determine if the given table is registered in pool.
     *
     * @param   string  $table
     * @return  bool
     */
    public function isRegistered(string $table): bool
    {
        return isset(self::$repositoryBindings[$table]);
    }

    /**
     * Initialize a new instance for query.
     *
     * @param   string  $group
     * @param   string  $table
     * @return  static  (cloned)
     *
     * @throws  BadMethodCallException
     */
    public function from(string $group, string $table): self
    {
        if (! $this->isRegistered($table)) {
            throw new BadMethodCallException("Repository {$table} is not registered");
        }

        // For compatibility.
        // Require a value even if not use group.
        $this->group = $group;
        $this->table = $table;

        $this->key = $this->resolveKey($group, $table);

        // todo
        // It's strange, I know.
        // ╮(╯_╰)╭
        return clone $this;
    }

    /**
     * Initialize a new instance for non group query.
     *
     * @param   string  $table
     * @return  static  (cloned)
     *
     * @throws  BadMethodCallException
     */
    public function table(string $table): self
    {
        return $this->from('', $table);
    }

    /**
     * Find a data by its id.
     *
     * @param   mixed  $id
     * @param   array|null  $default
     * @return  array|null
     */
    public function find($id, array $default = null): ?array
    {
        return Arr::get($this->get([$id]), $id, $default);
    }

    /**
     * Find multiple datas by their ids.
     *
     * @param   array  $ids
     * @return  array
     */
    public function get(array $ids): array
    {
        $result = [];

        if (empty($ids)) {
            return $result;
        }

        $cache = self::$client->hmget($this->key, $ids);

        // timestamp for determine expired data.
        $time = time();

        // those data needs fetch newest.
        $needFetch = [];

        /**
         * data repository.
         *
         * @var RedisHashRepository
         */
        $repository = $this->resolveRepository();

        // for in order returns.
        $i = 0;

        foreach ($ids as $id) {
            if (
                ! empty($cache[$i])
                && ($data = json_decode($cache[$i], true))
                && ! empty($data['value'])
                && (! empty($data['version']) && $data['version'] === $repository->version())
                && (empty($data['expire']) || $data['expire'] > $time)
            ) {
                $result[$id] = $data['value'];
            } else {
                $needFetch[] = $id;
                $result[$id] = null;
            }

            $i += 1;
        }

        if (! empty($needFetch)) {
            foreach ($this->fetch($repository, $needFetch) as $k => $v) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Get all data from hash table if allow the repository all cached.
     *
     * @return  array
     */
    public function all(): array
    {
        if (self::$client->get($this->key . ':forever')) {
            return array_map(function ($item) {
                $data = json_decode($item, true);
                return $data['value'] ?? null;
            }, self::$client->hgetall($this->key));
        }

        $repository = $this->resolveRepository();

        // can not cache all
        if (! $repository instanceof CacheForever) {
            // todo
            return [];
        }

        $result = $repository->all($this->group);

        if ($this->save($repository, $result)) {
            // set forever tag
            if ($repository->ttl()) {
                self::$client->set($this->key . ':forever', time(), 'EX', ($repository->ttl() * 60) - 60);
            } else {
                self::$client->set($this->key . ':forever', time());
            }
        }

        return $result;
    }

    /**
     * Delete multiple domains in hash table.
     *
     * @param   mixed  $ids
     * @return  int
     */
    public function delete($ids)
    {
        if (! is_array($ids)) {
            $ids = [$ids];
        }

        $result = self::$client->hdel($this->key, $ids);

        // todo
        // recache maybe is better?
        $this->clearForeverTag();

        return $result;
    }

    /**
     * Remove all datas.
     *
     * @return  int
     */
    public function clear()
    {
        $this->clearForeverTag();
        return self::$client->del($this->key);
    }

    /**
     * Fetch origin data from repository.
     *
     * @param   RedisHashRepository  $repository
     * @param   array  $ids
     * @param   bool  $save
     * @return  array
     */
    private function fetch(RedisHashRepository $repository, array $ids, bool $save = true): array
    {
        $result = $repository->fetch($ids, $this->group);

        if (empty($result)) {
            return [];
        }

        $save && $this->save($repository, $result);

        return $result;
    }

    /**
     * Save data into hash table.
     *
     * @param   RedisHashRepository  $repository
     * @param   array  $data
     * @return  bool
     */
    private function save(RedisHashRepository $repository, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        self::$client->hmset(
            $this->key,
            $this->formatValues(
                $data,
                $this->toExpiredTime($repository->ttl()),
                $repository->version()
            )
        );

        return true;
    }

    /**
     * Format data before save.
     *
     * @param   array  $collect
     * @param   int  $expire
     * @param   string  $version
     * @return  array
     */
    protected function formatValues(array $collect, int $expire, string $version): array
    {
        $result = [];

        foreach ($collect as $key => $item) {
            $result[$key] = json_encode([
                'value' => $item,
                'expire' => $expire,
                'version' => $version,
            ], JSON_UNESCAPED_UNICODE);
        }

        return $result;
    }

    /**
     * Convert TTL seconds to expired timestamps.
     *
     * @param   int  $seconds
     * @return  int
     */
    protected function toExpiredTime(int $seconds): int
    {
        return time() + $seconds;
    }

    /**
     * Clear forever tag.
     *
     * @return  void
     */
    private function clearForeverTag()
    {
        if (self::$client->exists($this->key . ':forever')) {
            self::$client->del($this->key . ':forever');
        }
    }

    /**
     * Get hash table key.
     *
     * @param   string  $group
     * @param   string  $table
     * @return  string
     */
    private function resolveKey(string $group, string $table): string
    {
        return self::$prefix . ':' . $table . ($group ? (':' . $group) : '');
    }

    /**
     * Get repository instance.
     *
     * @return  RedisHashRepository
     */
    private function resolveRepository(): RedisHashRepository
    {
        if (! isset(self::$repositoryInstances[$this->table])) {
            if (is_string(self::$repositoryBindings[$this->table])) {
                self::$repositoryInstances[$this->table] = new self::$repositoryBindings[$this->table];
            } elseif (self::$repositoryBindings[$this->table] instanceof Closure) {
                self::$repositoryInstances[$this->table] = self::$repositoryBindings[$this->table]();
            } else {
                self::$repositoryInstances[$this->table] = self::$repositoryBindings[$this->table];
            }
        }

        return self::$repositoryInstances[$this->table];
    }

    /**
     * Show component version.
     *
     * @return  string
     */
    public function version(): string
    {
        return static::VERSION;
    }

    public function __toString()
    {
        // dump simple info.
        return json_encode([
            'version' => $this->version(),
            'repositories' => array_keys($this->getRepositories()),
        ]);
    }
}
