<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\RateLimit;

use Illuminate\Redis\Connections\Connection;

/**
 * Sliding-window limiter: the timestamps of recent requests live in a
 * Redis sorted set, so every worker shares one budget (spec: ~60/min).
 *
 * Each call is a single Lua script (atomic) and uses the Redis server
 * clock, so workers with drifting clocks still agree.
 */
final readonly class RedisRateLimiter implements RequestRateLimiter
{
    /**
     * KEYS[1] = set key; ARGV = window (s), limit, unique member.
     * Returns 0 when a slot was taken, else milliseconds until one frees.
     */
    private const string ATTEMPT_SCRIPT = <<<'LUA'
        local window = tonumber(ARGV[1]) * 1000000
        local limit = tonumber(ARGV[2])
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
        if redis.call('ZCARD', KEYS[1]) < limit then
            redis.call('ZADD', KEYS[1], now, ARGV[3])
            redis.call('PEXPIRE', KEYS[1], math.ceil(window / 1000))
            return 0
        end
        local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
        return math.max(1, math.ceil((tonumber(oldest[2]) + window - now) / 1000))
        LUA;

    /**
     * KEYS[1] = set key; ARGV = window (s), limit.
     * Returns milliseconds until a slot frees (0 when one is free).
     */
    private const string AVAILABLE_IN_SCRIPT = <<<'LUA'
        local window = tonumber(ARGV[1]) * 1000000
        local limit = tonumber(ARGV[2])
        local time = redis.call('TIME')
        local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
        if redis.call('ZCARD', KEYS[1]) < limit then
            return 0
        end
        local oldest = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
        return math.max(1, math.ceil((tonumber(oldest[2]) + window - now) / 1000))
        LUA;

    /**
     * @param  int  $maxRequests  0 or less disables the limit
     */
    public function __construct(
        private Connection $redis,
        private string $key,
        private int $maxRequests,
        private int $windowSeconds,
    ) {}

    public function attempt(): bool
    {
        if ($this->maxRequests <= 0) {
            return true;
        }

        return $this->run(self::ATTEMPT_SCRIPT, bin2hex(random_bytes(8))) === 0;
    }

    public function availableIn(): int
    {
        if ($this->maxRequests <= 0) {
            return 0;
        }

        return (int) ceil($this->run(self::AVAILABLE_IN_SCRIPT) / 1000);
    }

    private function run(string $script, string ...$extra): int
    {
        // Laravel's eval($script, $numberOfKeys, ...$arguments) works for
        // both phpredis and Predis; PHPStan checks it against the native
        // \Redis::eval() signature instead.
        // @phpstan-ignore argument.type, argument.type
        return (int) $this->redis->eval($script, 1, $this->key, $this->windowSeconds, $this->maxRequests, ...$extra);
    }
}
