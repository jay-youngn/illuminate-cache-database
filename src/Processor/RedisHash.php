<?php

namespace Zeigo\Illuminate\CacheDatabase\Processor;

use BadMethodCallException;
use Illuminate\Support\Arr;
use Predis\ClientInterface;
use Zeigo\Illuminate\CacheDatabase\Contracts\CacheForever;
use Zeigo\Illuminate\CacheDatabase\Contracts\RedisHashRepository;

/**
 * Redis hash stored.
 */
class RedisHash
{
    /**
     * Component version.
     *
     * @var string
     */
    const VERSION = '2.1.2';

    /**
     * Redis key.
     *
     * @var string
     */
    private $key;

    /**
     * Data sharding.
     *
     * @var string
     */
    private $group;

    /**
     * Table name.
     *
     * @var string
     */
    private $table;

    /**
     * Predis client instance.
     *
     * @var Predis\ClientInterface
     */
    private static $client;

    /**
     * Redis key prefix.
     *
     * @var string
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
     * @param \Predis\ClientInterface $client
     * @param string $prefix
     */
    public function __construct(ClientInterface $client, string $prefix)
    {
        self::$client = $client;
        self::$prefix = $prefix;
    }

    /**
     * Register a repository with the table.
     *
     * @param string $table
     * @param string|Closure $class
     * @return void
     */
    public function register(string $table, $class)
    {
        self::$repositoryBindings[$table] = $class;

        unset(self::$repositoryInstances[$table]);
    }

    /**
     * Mass register repositories.
     *
     * @param array $repositories
     * @return static
     */
    public function fill(array $repositories): self
    {
        foreach ($repositories as $table => $class) {
            $this->register($table, $class);
        }

        return $this;
    }

    /**
     * Get current registered repositories.
     *
     * @param string $table
     * @return array
     */
    public function getRepositories(): array
    {
        return self::$repositoryBindings;
    }

    /**
     * Determine if the given table is registered in pool.
     *
     * @param string $table
     * @return bool
     */
    public function isRegistered(string $table): bool
    {
        return isset(self::$repositoryBindings[$table]);
    }

    /**
     * Build a new instance for query.
     *
     * @param string $group
     * @param string $table
     * @return static (cloned)
     *
     * @throws \BadMethodCallException
     */
    public function from(string $group, string $table): self
    {
        if (! $this->isRegistered($table)) {
            throw new BadMethodCallException("Repository {$table} is not registered");
        }

        // For compatibility.
        // Require a non-null value even if not use group.
        $this->group = $group;
        $this->table = $table;

        $this->key = $this->resolveKey($group, $table);

        // todo
        // It's strange.
        // ╮(╯_╰)╭
        $newInstance = clone $this;

        // Do not keep modified properties in singleton.
        $this->group = null;
        $this->table = null;
        $this->key = null;

        return $newInstance;
    }

    /**
     * Build a new instance for non group query.
     *
     * @param string $table
     * @return static (cloned)
     *
     * @throws \BadMethodCallException
     */
    public function table(string $table): self
    {
        return $this->from('', $table);
    }

    /**
     * Find a data by its id.
     *
     * @param mixed $id
     * @param array|null $default
     * @return array|null
     */
    public function find($id, array $default = null): ?array
    {
        return Arr::get($this->get([$id]), $id, $default);
    }

    /**
     * Find datas by multiple ids.
     *
     * @param array $ids
     * @return array
     */
    public function get(array $ids): array
    {
        $this->abortIfNoProperties();

        $result = [];

        if (empty($ids)) {
            return $result;
        }

        /**
         * Data repository.
         *
         * @var RedisHashRepository
         */
        $repository = $this->resolveRepository();

        // Current component & repository version tag.
        $version = $this->resolveVersionTag($repository);

        // Timestamp for determine expired data.
        $time = time();

        // Those data needs refetch.
        $needRefetch = [];

        // Be used for pluck 'HMGET' returns.
        $i = 0;

        $values = self::$client->hmget($this->key, $ids);

        foreach ($ids as $id) {
            if (
                ! empty($values[$i])
                && ($data = json_decode($values[$i], true))
                && ! empty($data['value'])
                && (! empty($data['version']) && $data['version'] === $version)
                && (empty($data['expire']) || $data['expire'] > $time)
            ) {
                $result[$id] = $data['value'];
            } else {
                $needRefetch[] = $id;
                $result[$id] = null;
            }

            $i += 1;
        }

        if (! empty($needRefetch)) {
            foreach ($this->fetch($repository, $needRefetch) as $k => $v) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * Get all data from hash table if allow the repository all cached.
     *
     * @return array
     */
    public function all(): array
    {
        $this->abortIfNoProperties();

        $repository = $this->resolveRepository();

        if (! $repository instanceof CacheForever) {
            throw new BadMethodCallException(get_class($repository).' must be implement Zeigo\Illuminate\CacheDatabase\Contracts\CacheForever before calling "all"');
        }

        if (self::$client->exists($this->key.':forever')) {
            $deletedIds = self::$client->smembers($this->key.':deleted');

            if (! empty($deletedIds)) {
                // Refetch and save.
                $this->fetch($repository, $deletedIds, true);
            }

            return array_map(function ($item) {
                $data = json_decode($item, true);
                return $data['value'] ?? null;
            }, self::$client->hgetall($this->key));
        }

        $result = $repository->all($this->group);

        if ($this->save($repository, $result)) {
            if ($repository->ttl()) {
                // Let this tag expire 10 seconds early when repository has TTL.
                // So that cached data is always timely.
                self::$client->set($this->key.':forever', time(), 'EX', max(1, $repository->ttl() - 10));
            } else {
                self::$client->set($this->key.':forever', time());
            }
        }

        return $result;
    }

    /**
     * Delete multiple domains in hash table.
     *
     * @param mixed $ids
     * @return void
     */
    public function delete($ids)
    {
        $this->abortIfNoProperties();

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        if ($this->resolveRepository() instanceof CacheForever) {
            self::$client->transaction(function ($multiExec) use ($ids) {
                $multiExec->hdel($this->key, $ids);
                $multiExec->sadd($this->key.':deleted', $ids);
            });
        } else {
            self::$client->hdel($this->key, $ids);
        }
    }

    /**
     * Remove all datas.
     *
     * @return void
     */
    public function clear()
    {
        $this->abortIfNoProperties();

        self::$client->transaction(function ($multiExec) {
            $multiExec->del($this->key.':forever');
            $multiExec->del($this->key.':deleted');
            $multiExec->del($this->key);
        });
    }

    /**
     * Clear forever tag.
     *
     * @return void
     */
    public function clearForeverTag()
    {
        $this->abortIfNoProperties();

        if (! $this->resolveRepository() instanceof CacheForever) {
            return;
        }

        // IF do this, data will be refetched from repository when the "all" method is called.
        self::$client->del($this->key.':forever');
    }

    /**
     * Throw a BadMethodCallException when required attributes are missing.
     *
     * @return void
     *
     * @throws \BadMethodCallException
     */
    protected function abortIfNoProperties()
    {
        if (! isset($this->key, $this->group, $this->table)) {
            throw new BadMethodCallException('Missing required attributes. Try use table() or from() to build new instance.');
        }
    }

    /**
     * Format data before save.
     *
     * @param RedisHashRepository $repository
     * @param array $data
     * @return array
     */
    protected function formatValues(RedisHashRepository $repository, array $data): array
    {
        $expire = $this->toExpiredTime($repository->ttl());

        $version = $this->resolveVersionTag($repository);

        $result = [];

        foreach ($data as $key => $item) {
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
     * @param int $seconds
     * @return int
     */
    protected function toExpiredTime(int $seconds): int
    {
        return time() + $seconds;
    }

    /**
     * Get hash table key.
     *
     * @param string $group
     * @param string $table
     * @return string
     */
    protected function resolveKey(string $group, string $table): string
    {
        return self::$prefix.':'.$table.($group ? (':'.$group) : '');
    }

    /**
     * Fetch origin data from repository.
     *
     * @param RedisHashRepository $repository
     * @param array $ids
     * @param bool $save
     * @return array
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
     * @param RedisHashRepository $repository
     * @param array $data
     * @return bool
     */
    private function save(RedisHashRepository $repository, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data = $this->formatValues($repository, $data);

        if ($repository instanceof CacheForever) {
            // Remove item from deleted sets when saving newest data.
            self::$client->transaction(function ($multiExec) use ($data) {
                $multiExec->hmset($this->key, $data);
                $multiExec->srem($this->key.':deleted', array_keys($data));
            });
        } else {
            self::$client->hmset($this->key, $data);
        }

        return true;
    }

    /**
     * Get repository instance.
     *
     * @return RedisHashRepository
     */
    private function resolveRepository(): RedisHashRepository
    {
        if (! isset(self::$repositoryInstances[$this->table])) {
            if (is_string(self::$repositoryBindings[$this->table])) {
                self::$repositoryInstances[$this->table] = new self::$repositoryBindings[$this->table];
            } else {
                self::$repositoryInstances[$this->table] = value(self::$repositoryBindings[$this->table]);
            }
        }

        return self::$repositoryInstances[$this->table];
    }

    /**
     * Get version tag for component and repository.
     *
     * @param RedisHashRepository $repository
     * @return string
     */
    private function resolveVersionTag(RedisHashRepository $repository): string
    {
        return $this->version().'@'.$repository->version();
    }

    /**
     * Show component version.
     *
     * @return string
     */
    public function version(): string
    {
        return static::VERSION;
    }

    public function __toString()
    {
        return json_encode([
            'version' => $this->version(),
            'repositories' => array_keys($this->getRepositories()),
        ]);
    }
}
