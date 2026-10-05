<?php

use Falak\Sites\Application\Compose\RedisCommand;

it('reads the memory limit, eviction and AOF flags of a compose Redis command', function (mixed $command, array $expected) {
    expect(RedisCommand::settings($command))->toBe($expected);
})->with([
    'string' => ['redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru', ['persistence' => 'aof', 'maxmemory_mb' => 256, 'eviction' => 'allkeys-lru']],
    'list' => [['valkey-server', '--maxmemory', '1gb'], ['maxmemory_mb' => 1024]],
    'flags only' => ['--maxmemory 2g --appendonly no', ['maxmemory_mb' => 1907]],
    'bytes, at least 16 MB' => ['redis-server --maxmemory 1048576', ['maxmemory_mb' => 16]],
    'quoted policy' => ['redis-server --maxmemory-policy "volatile-ttl"', ['eviction' => 'volatile-ttl']],
    'no limit' => ['redis-server --maxmemory 0', []],
    'unknown values' => ['redis-server --maxmemory lots --maxmemory-policy random --appendonly maybe', []],
    'a shell' => [['sh', '-c', 'redis-server --maxmemory 256mb'], []],
    'a config file' => ['redis-server /usr/local/etc/redis/redis.conf', []],
    'none' => [null, []],
]);
