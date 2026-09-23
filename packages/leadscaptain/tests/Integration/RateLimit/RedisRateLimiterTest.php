<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Integration\RateLimit;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Leadscaptain\Infrastructure\RateLimit\RedisRateLimiter;
use Leadscaptain\Tests\TestCase;
use Throwable;

/**
 * Runs against a real Redis (REDIS_HOST, default "redis"); skipped when
 * none is reachable. Start one locally with:
 *   docker compose --profile queue up -d redis
 */
final class RedisRateLimiterTest extends TestCase
{
    private Connection $redis;

    private string $key;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 15,
            'timeout' => 1.0,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->redis = Redis::connection();
            $this->redis->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        $this->key = 'leadscaptain:test:rate:'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis, $this->key)) {
            $this->redis->del($this->key);
        }

        parent::tearDown();
    }

    public function test_it_allows_requests_up_to_the_limit(): void
    {
        $limiter = $this->limiter(maxRequests: 3);

        $this->assertTrue($limiter->attempt());
        $this->assertTrue($limiter->attempt());
        $this->assertSame(0, $limiter->availableIn());
        $this->assertTrue($limiter->attempt());
        $this->assertFalse($limiter->attempt());
    }

    public function test_it_reports_when_the_next_slot_frees_up(): void
    {
        $limiter = $this->limiter(maxRequests: 1, windowSeconds: 60);

        $limiter->attempt();
        $wait = $limiter->availableIn();

        $this->assertGreaterThanOrEqual(59, $wait);
        $this->assertLessThanOrEqual(60, $wait);
    }

    public function test_slots_free_up_when_the_window_slides(): void
    {
        $limiter = $this->limiter(maxRequests: 1, windowSeconds: 1);

        $this->assertTrue($limiter->attempt());
        $this->assertFalse($limiter->attempt());

        usleep(1_100_000);

        $this->assertTrue($limiter->attempt());
    }

    public function test_limiters_sharing_a_key_share_the_budget(): void
    {
        $first = $this->limiter(maxRequests: 2);
        $second = $this->limiter(maxRequests: 2);

        $this->assertTrue($first->attempt());
        $this->assertTrue($second->attempt());
        $this->assertFalse($first->attempt());
    }

    public function test_the_key_expires_after_the_window(): void
    {
        $this->limiter(maxRequests: 5, windowSeconds: 30)->attempt();

        $ttl = (int) $this->redis->pttl($this->key);

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30_000, $ttl);
    }

    public function test_a_limit_of_zero_disables_limiting(): void
    {
        $limiter = $this->limiter(maxRequests: 0);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($limiter->attempt());
        }

        $this->assertSame(0, $limiter->availableIn());
        $this->assertSame(0, (int) $this->redis->exists($this->key));
    }

    private function limiter(int $maxRequests, int $windowSeconds = 60): RedisRateLimiter
    {
        return new RedisRateLimiter($this->redis, $this->key, $maxRequests, $windowSeconds);
    }
}
