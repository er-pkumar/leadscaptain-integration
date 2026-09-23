<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Infrastructure\RateLimit\RequestRateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * LeadsApiClient over Laravel's HTTP client.
 *
 * - fetchPage() makes one attempt and leaves retries and rate limiting
 *   to its caller (the page job releases itself instead of sleeping).
 * - fetchPages() runs outside a job (--now, unknown last page), so it
 *   takes one rate-limit slot per request, sends the pages concurrently
 *   with Http::pool() and retries retryable failures per RetryPolicy.
 *
 * Every attempt is logged with page, attempt, status and duration. The
 * API key is never logged.
 */
final readonly class LeadscaptainHttpClient implements LeadsApiClient
{
    public const string LOG_MESSAGE = 'leadscaptain.api_request';

    public function __construct(
        private Factory $http,
        private ApiSettings $settings,
        private RetryPolicy $retry,
        private LoggerInterface $logger,
        private ?RequestRateLimiter $rateLimiter = null,
    ) {}

    public function fetchPage(PageNumber $page): RawPage
    {
        $started = hrtime(true);

        try {
            $response = $this->request($this->http->baseUrl($this->settings->baseUrl))
                ->get($this->settings->leadsPath, $this->query($page));
        } catch (ConnectionException $e) {
            $response = $e;
        }

        return $this->toRawPage($page, $response, self::elapsedMs($started), attempt: 1);
    }

    public function fetchPages(PageNumber ...$pages): array
    {
        /** @var array<int, PageNumber> $pending */
        $pending = [];

        foreach ($pages as $page) {
            $pending[$page->value] = $page;
        }

        $results = [];
        $retries = 0;

        while ($pending !== []) {
            $this->takeSlots(count($pending));

            $started = hrtime(true);
            $responses = $this->http->pool(function (Pool $pool) use ($pending): void {
                foreach ($pending as $number => $page) {
                    $this->request($pool->as((string) $number))->get($this->settings->leadsPath, $this->query($page));
                }
            });
            $elapsedMs = self::elapsedMs($started);

            $retryPages = [];
            $delayMs = 0;

            foreach ($pending as $number => $page) {
                try {
                    $results[$number] = $this->toRawPage($page, $responses[$number] ?? null, $elapsedMs, $retries + 1);
                } catch (LeadsApiException $e) {
                    if ($this->retry->shouldRetry($e, $retries)) {
                        $retryPages[$number] = $page;
                        $delayMs = max($delayMs, $this->retry->delayMs($e, $retries + 1));

                        continue;
                    }

                    if ($e->isRetryable()) {
                        $this->log('leads', $number, $retries + 1, $e->status, 0.0, 'gave_up', error: "No retries left: {$e->getMessage()}");
                    }

                    $results[$number] = new PageFetchFailure($page, $e);
                }
            }

            $pending = $retryPages;

            if ($pending !== []) {
                $retries++;
                Sleep::for($delayMs)->milliseconds();
            }
        }

        ksort($results);

        return $results;
    }

    public function countLeads(): ?int
    {
        $started = hrtime(true);

        try {
            $response = $this->request($this->http->baseUrl($this->settings->baseUrl))->get($this->settings->countPath);
        } catch (ConnectionException $e) {
            $this->log('count', null, 1, null, self::elapsedMs($started), 'failed', error: $e->getMessage());

            return null;
        }

        $count = $response->successful() ? self::count($response) : null;

        $this->log(
            'count',
            null,
            1,
            $response->status(),
            self::transferMs($response) ?? self::elapsedMs($started),
            $count === null ? 'failed' : 'ok',
            error: $count === null ? 'no usable count in the response' : null,
        );

        return $count;
    }

    private function request(PendingRequest $request): PendingRequest
    {
        $request = $request
            ->baseUrl($this->settings->baseUrl)
            ->acceptJson()
            ->timeout($this->settings->timeout)
            ->connectTimeout($this->settings->connectTimeout);

        if ($this->settings->apiKey === null) {
            return $request;
        }

        return $this->settings->authScheme === ApiSettings::AUTH_BEARER
            ? $request->withToken($this->settings->apiKey)
            : $request->withHeaders([$this->settings->apiKeyHeader => $this->settings->apiKey]);
    }

    /**
     * @return array<string, int>
     */
    private function query(PageNumber $page): array
    {
        return [
            $this->settings->pageParam => $page->value,
            $this->settings->pageSizeParam => $this->settings->pageSize,
        ];
    }

    /**
     * @throws LeadsApiException
     */
    private function toRawPage(PageNumber $page, mixed $response, float $elapsedMs, int $attempt): RawPage
    {
        if (! $response instanceof Response) {
            $reason = $response instanceof Throwable ? $response->getMessage() : 'no response received';

            throw $this->logged(LeadsApiException::forPage(
                $page,
                null,
                $reason,
                previous: $response instanceof Throwable ? $response : null,
            ), $attempt, $elapsedMs);
        }

        $durationMs = self::transferMs($response) ?? $elapsedMs;

        if (! $response->successful()) {
            throw $this->logged(LeadsApiException::forPage(
                $page,
                $response->status(),
                self::reason($response),
                self::retryAfterSeconds($response),
            ), $attempt, $durationMs);
        }

        $body = $response->json();

        try {
            if (! is_array($body)) {
                throw LeadsApiException::invalidResponse($page, 'body is not JSON', $response->status());
            }

            $rawPage = RawPage::fromResponse($page, $body);
        } catch (LeadsApiException $e) {
            throw $this->logged($e, $attempt, $durationMs);
        }

        $this->log('leads', $page->value, $attempt, $response->status(), $durationMs, 'ok', records: count($rawPage));

        return $rawPage;
    }

    private function logged(LeadsApiException $e, int $attempt, float $durationMs): LeadsApiException
    {
        $this->log(
            'leads',
            $e->page,
            $attempt,
            $e->status,
            $durationMs,
            $e->isRetryable() ? 'retryable' : 'failed',
            error: $e->getMessage(),
        );

        return $e;
    }

    private function log(
        string $endpoint,
        ?int $page,
        int $attempt,
        ?int $status,
        float $durationMs,
        string $outcome,
        ?int $records = null,
        ?string $error = null,
    ): void {
        $context = array_filter([
            'endpoint' => $endpoint,
            'page' => $page,
            'attempt' => $attempt,
            'status' => $status,
            'duration_ms' => round($durationMs, 1),
            'outcome' => $outcome,
            'records' => $records,
            'error' => $error,
        ], static fn (mixed $value): bool => $value !== null);

        match ($outcome) {
            'ok' => $this->logger->info(self::LOG_MESSAGE, $context),
            'retryable' => $this->logger->warning(self::LOG_MESSAGE, $context),
            default => $this->logger->error(self::LOG_MESSAGE, $context),
        };
    }

    private function takeSlots(int $requests): void
    {
        if ($this->rateLimiter === null) {
            return;
        }

        for ($i = 0; $i < $requests; $i++) {
            while (! $this->rateLimiter->attempt()) {
                Sleep::for(max(1, $this->rateLimiter->availableIn()))->seconds();
            }
        }
    }

    private static function reason(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : $response->reason();
    }

    private static function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - time());
    }

    private static function count(Response $response): ?int
    {
        foreach (['count', 'total', 'data.count'] as $key) {
            $value = $response->json($key);

            if (is_int($value) && $value >= 0) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** Transfer time reported by Guzzle, when available. */
    private static function transferMs(Response $response): ?float
    {
        $seconds = $response->handlerStats()['total_time'] ?? null;

        return is_numeric($seconds) && $seconds > 0 ? (float) $seconds * 1000 : null;
    }

    private static function elapsedMs(int|float $startedNs): float
    {
        return (hrtime(true) - $startedNs) / 1_000_000;
    }
}
