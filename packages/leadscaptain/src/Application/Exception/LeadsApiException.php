<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Exception;

use Leadscaptain\Domain\Sync\PageNumber;
use RuntimeException;
use Throwable;

/**
 * A request to the Leadscaptain API failed. A null status means the
 * request never got a response (timeout, DNS, connection refused).
 */
final class LeadsApiException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?int $page,
        public readonly ?int $status,
        public readonly ?int $retryAfterSeconds = null,
        private readonly bool $invalidResponse = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function forPage(
        PageNumber $page,
        ?int $status,
        string $reason,
        ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ): self {
        $message = $status === null
            ? "Leadscaptain API request for page {$page->value} failed: {$reason}"
            : "Leadscaptain API request for page {$page->value} failed with HTTP {$status}: {$reason}";

        return new self($message, $page->value, $status, $retryAfterSeconds, previous: $previous);
    }

    public static function invalidResponse(PageNumber $page, string $reason, ?int $status = null): self
    {
        return new self(
            "Leadscaptain API returned an invalid response for page {$page->value}: {$reason}",
            $page->value,
            $status,
            invalidResponse: true,
        );
    }

    /**
     * Timeouts, connection errors, 429 and 5xx are worth another attempt.
     */
    public function isRetryable(): bool
    {
        if ($this->invalidResponse) {
            return false;
        }

        return $this->status === null || $this->status === 429 || $this->status >= 500;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
