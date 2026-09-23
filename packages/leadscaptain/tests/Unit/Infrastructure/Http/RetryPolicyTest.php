<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Infrastructure\Http;

use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Infrastructure\Http\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private RetryPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RetryPolicy(times: 3, backoffMs: [1000, 5000, 30000], rateLimitBackoffMs: [1000, 2000, 4000]);
    }

    public function test_it_reads_the_package_config(): void
    {
        $policy = RetryPolicy::fromConfig(['times' => 2, 'backoff_ms' => [10, 20], 'rate_limit_backoff_ms' => [30]]);

        $this->assertSame(2, $policy->times);
        $this->assertSame([10, 20], $policy->backoffMs);
        $this->assertSame([30], $policy->rateLimitBackoffMs);
    }

    public function test_missing_config_falls_back_to_the_spec_values(): void
    {
        $policy = RetryPolicy::fromConfig([]);

        $this->assertSame(3, $policy->times);
        $this->assertSame([1000, 5000, 30000], $policy->backoffMs);
        $this->assertSame([1000, 2000, 4000], $policy->rateLimitBackoffMs);
    }

    /**
     * @return iterable<string, array{?int, int, bool}>
     */
    public static function retryDecisions(): iterable
    {
        yield '503 first retry' => [503, 0, true];
        yield '500 third retry' => [500, 2, true];
        yield '500 after three retries' => [500, 3, false];
        yield '429 first retry' => [429, 0, true];
        yield 'timeout' => [null, 1, true];
        yield '401 never' => [401, 0, false];
        yield '404 never' => [404, 0, false];
    }

    #[DataProvider('retryDecisions')]
    public function test_should_retry(?int $status, int $retriesDone, bool $expected): void
    {
        $this->assertSame($expected, $this->policy->shouldRetry(self::failure($status), $retriesDone));
    }

    public function test_server_errors_use_the_5xx_schedule(): void
    {
        $this->assertSame(1000, $this->policy->delayMs(self::failure(503), 1));
        $this->assertSame(5000, $this->policy->delayMs(self::failure(500), 2));
        $this->assertSame(30000, $this->policy->delayMs(self::failure(null), 3));
    }

    public function test_rate_limits_use_the_429_schedule(): void
    {
        $this->assertSame(1000, $this->policy->delayMs(self::failure(429), 1));
        $this->assertSame(2000, $this->policy->delayMs(self::failure(429), 2));
        $this->assertSame(4000, $this->policy->delayMs(self::failure(429), 3));
    }

    public function test_retry_after_wins_for_rate_limits(): void
    {
        $this->assertSame(7000, $this->policy->delayMs(self::failure(429, retryAfter: 7), 1));
    }

    public function test_retry_after_also_wins_for_server_errors(): void
    {
        $this->assertSame(3000, $this->policy->delayMs(self::failure(503, retryAfter: 3), 1));
    }

    public function test_a_retry_beyond_the_schedule_reuses_the_last_delay(): void
    {
        $this->assertSame(30000, $this->policy->delayMs(self::failure(500), 9));
    }

    public function test_an_empty_schedule_means_no_delay(): void
    {
        $policy = new RetryPolicy(times: 1, backoffMs: [], rateLimitBackoffMs: []);

        $this->assertSame(0, $policy->delayMs(self::failure(500), 1));
    }

    private static function failure(?int $status, ?int $retryAfter = null): LeadsApiException
    {
        return LeadsApiException::forPage(PageNumber::first(), $status, 'x', $retryAfter);
    }
}
