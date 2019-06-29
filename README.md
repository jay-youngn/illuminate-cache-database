# illuminate-cache-database

[![Total Downloads](https://poser.pugx.org/ginnerpeace/illuminate-cache-database/downloads.svg)](https://packagist.org/packages/ginnerpeace/illuminate-cache-database)
[![Latest Stable Version](https://poser.pugx.org/ginnerpeace/illuminate-cache-database/v/stable.svg)](https://packagist.org/packages/ginnerpeace/illuminate-cache-database)
[![Latest Unstable Version](https://poser.pugx.org/ginnerpeace/illuminate-cache-database/v/unstable.svg)](https://packagist.org/packages/ginnerpeace/illuminate-cache-database)
[![License](https://poser.pugx.org/ginnerpeace/illuminate-cache-database/license.svg)](https://packagist.org/packages/ginnerpeace/illuminate-cache-database)

> Cache-based data query.

## Getting started

### Install
```bash
composer require ginnerpeace/illuminate-cache-database
```

### Add service provider to config.
> After Laravel 5.5, the package auto-discovery is supported
```javascript
{
    "providers": [
        "Zeigo\\Illuminate\\CacheDatabase\\RedisHashProvider"
    ],
    "aliases": {
        "RedisHashQuery": "Zeigo\\Illuminate\\CacheDatabase\\Facades\\RedisHashQuery"
    }
}
```

### Publish resources
```bash
php artisan vendor:publish --provider="Zeigo\Illuminate\CacheDatabase\RedisHashProvider"
```

### Create repository
```php
<?php

namespace DataRepository;

use App\Models\User;
use Zeigo\Illuminate\CacheDatabase\Contracts\RedisHashRepository;

class Users implements RedisHashRepository
{
    public function version(): string
    {
        return '1.0';
    }

    public function ttl(): int
    {
        return 60;
    }

    public function fetch(string $group, array $ids): array
    {
        // The group param is design for data sharding.
        // Use or not is up to u.
        $result = User::whereGroup($group)->find($ids, [
            'id',
            'username',
        ]);

        if ($result->isEmpty()) {
            return [];
        }

        return $result->keyBy('id')->toArray();
    }
}
```

### Appends useable repository to config
```php
<?php

return [
    'connection' => 'cache',
    'prefix' => 'hash-database',
    'repositories' => [
        'users' => DataRepository\Users::class,
    ],
];
```

## Enjoy
```php
<?php

RedisHashQuery::from('group1', 'users')->get([1, 2, 3]);
// dump
[
    1 => [
        'id' => 1,
        'username' => 'First user',
    ],
    2 => [
        'id' => 2,
        'username' => 'Second user',
    ],
    // no data
    3 => null,
];

// Data saved for 60 minutes into redis hash table: "hash-database:users:group1"
```
