<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\RateLimit;

/**
 * Limits requests to the Leadscaptain API across every worker.
 */
interface RequestRateLimiter
{
    /**
     * Takes one request slot. Returns false when the limit is reached;
     * the caller then waits availableIn() seconds (a queue job releases
     * itself instead of sleeping).
     */
    public function attempt(): bool;

    /**
     * Seconds until a slot frees up; 0 when one is free now.
     */
    public function availableIn(): int;
}
