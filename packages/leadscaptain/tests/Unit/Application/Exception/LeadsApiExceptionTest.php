<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Exception;

use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LeadsApiExceptionTest extends TestCase
{
    public function test_for_page_keeps_the_context(): void
    {
        $previous = new RuntimeException('boom');

        $exception = LeadsApiException::forPage(new PageNumber(4), 503, 'Database is initializing', 7, $previous);

        $this->assertSame(4, $exception->page);
        $this->assertSame(503, $exception->status);
        $this->assertSame(7, $exception->retryAfterSeconds);
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('Leadscaptain API request for page 4 failed with HTTP 503: Database is initializing', $exception->getMessage());
    }

    public function test_connection_errors_have_no_status(): void
    {
        $exception = LeadsApiException::forPage(new PageNumber(2), null, 'Connection timed out');

        $this->assertNull($exception->status);
        $this->assertSame('Leadscaptain API request for page 2 failed: Connection timed out', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{?int, bool}>
     */
    public static function retryableStatuses(): iterable
    {
        yield 'timeout or connection error' => [null, true];
        yield 'rate limited' => [429, true];
        yield 'server error' => [500, true];
        yield 'bad gateway' => [502, true];
        yield 'database initializing' => [503, true];
        yield 'unauthorized' => [401, false];
        yield 'forbidden' => [403, false];
        yield 'not found' => [404, false];
        yield 'unprocessable' => [422, false];
    }

    #[DataProvider('retryableStatuses')]
    public function test_retryable_statuses(?int $status, bool $retryable): void
    {
        $this->assertSame($retryable, LeadsApiException::forPage(PageNumber::first(), $status, 'x')->isRetryable());
    }

    public function test_rate_limited_is_only_429(): void
    {
        $this->assertTrue(LeadsApiException::forPage(PageNumber::first(), 429, 'x')->isRateLimited());
        $this->assertFalse(LeadsApiException::forPage(PageNumber::first(), 503, 'x')->isRateLimited());
    }

    public function test_unauthorized_covers_401_and_403(): void
    {
        $this->assertTrue(LeadsApiException::forPage(PageNumber::first(), 401, 'x')->isUnauthorized());
        $this->assertTrue(LeadsApiException::forPage(PageNumber::first(), 403, 'x')->isUnauthorized());
        $this->assertFalse(LeadsApiException::forPage(PageNumber::first(), 500, 'x')->isUnauthorized());
    }

    public function test_an_invalid_response_body_is_not_retryable(): void
    {
        $exception = LeadsApiException::invalidResponse(new PageNumber(3), 'missing "data" list');

        $this->assertFalse($exception->isRetryable());
        $this->assertSame(3, $exception->page);
        $this->assertSame('Leadscaptain API returned an invalid response for page 3: missing "data" list', $exception->getMessage());
    }
}
