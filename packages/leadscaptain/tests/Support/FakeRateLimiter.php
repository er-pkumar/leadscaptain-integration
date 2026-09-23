<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Infrastructure\RateLimit\RequestRateLimiter;

/**
 * Denies the first $denials attempts, then allows every request.
 */
final class FakeRateLimiter implements RequestRateLimiter
{
    public int $attempts = 0;

    public function __construct(
        private int $denials = 0,
        private readonly int $waitSeconds = 2,
    ) {}

    public function attempt(): bool
    {
        $this->attempts++;

        if ($this->denials > 0) {
            $this->denials--;

            return false;
        }

        return true;
    }

    public function availableIn(): int
    {
        return $this->waitSeconds;
    }
}
