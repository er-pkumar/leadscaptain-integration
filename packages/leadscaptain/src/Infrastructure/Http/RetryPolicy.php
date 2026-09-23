<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Http;

use Leadscaptain\Application\Exception\LeadsApiException;

/**
 * When and how long to wait before retrying a failed API request.
 *
 * Timeouts and 5xx use `backoff_ms` (spec: 1s, 5s, 30s); 429 uses
 * `rate_limit_backoff_ms` (spec: 1s, 2s, 4s). A Retry-After header from
 * the API wins over both. Shared by the HTTP client and the queue jobs.
 */
final readonly class RetryPolicy
{
    private const array DEFAULT_BACKOFF_MS = [1000, 5000, 30000];

    private const array DEFAULT_RATE_LIMIT_BACKOFF_MS = [1000, 2000, 4000];

    /**
     * @param  int  $times  Retries after the first attempt
     * @param  list<int>  $backoffMs
     * @param  list<int>  $rateLimitBackoffMs
     */
    public function __construct(
        public int $times,
        public array $backoffMs,
        public array $rateLimitBackoffMs,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `leadscaptain.retry` config array
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            times: is_numeric($config['times'] ?? null) ? max(0, (int) $config['times']) : 3,
            backoffMs: self::delays($config['backoff_ms'] ?? null, self::DEFAULT_BACKOFF_MS),
            rateLimitBackoffMs: self::delays($config['rate_limit_backoff_ms'] ?? null, self::DEFAULT_RATE_LIMIT_BACKOFF_MS),
        );
    }

    public function shouldRetry(LeadsApiException $failure, int $retriesDone): bool
    {
        return $failure->isRetryable() && $retriesDone < $this->times;
    }

    /**
     * @param  int  $retryNumber  1 for the first retry
     */
    public function delayMs(LeadsApiException $failure, int $retryNumber): int
    {
        if ($failure->retryAfterSeconds !== null) {
            return $failure->retryAfterSeconds * 1000;
        }

        $schedule = $failure->isRateLimited() ? $this->rateLimitBackoffMs : $this->backoffMs;

        if ($schedule === []) {
            return 0;
        }

        return $schedule[min(max(1, $retryNumber), count($schedule)) - 1];
    }

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    private static function delays(mixed $value, array $default): array
    {
        if (! is_array($value) || $value === []) {
            return $default;
        }

        return array_values(array_map(static fn (mixed $ms): int => max(0, (int) $ms), array_filter($value, 'is_numeric')));
    }
}
