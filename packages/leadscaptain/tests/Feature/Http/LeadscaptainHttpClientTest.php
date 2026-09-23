<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Feature\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Infrastructure\Http\ApiSettings;
use Leadscaptain\Infrastructure\Http\LeadscaptainHttpClient;
use Leadscaptain\Infrastructure\Http\RetryPolicy;
use Leadscaptain\Infrastructure\RateLimit\RequestRateLimiter;
use Leadscaptain\Tests\Support\FakeRateLimiter;
use Leadscaptain\Tests\Support\Fixture;
use Leadscaptain\Tests\Support\RecordingLogger;
use Leadscaptain\Tests\TestCase;

final class LeadscaptainHttpClientTest extends TestCase
{
    private const string BASE = 'https://api.test';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
        Http::preventStrayRequests();
        Sleep::fake();
    }

    // ---- fetchPage ---------------------------------------------------------------

    public function test_it_requests_a_page_with_the_configured_path_query_and_key_header(): void
    {
        Http::fake([self::BASE.'/api/v1/leads*' => Http::response(Fixture::json('api/leads-page.json'))]);

        $page = $this->client()->fetchPage(new PageNumber(3));

        $this->assertInstanceOf(RawPage::class, $page);
        $this->assertSame(3, $page->page->value);
        $this->assertCount(1, $page);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::BASE.'/api/v1/leads?page=3&limit=100'
            && $request->hasHeader('X-API-Key', 'secret-key')
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_it_can_send_the_key_as_a_bearer_token(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['data' => []])]);

        $this->client(['auth' => ['scheme' => 'bearer']])->fetchPage(PageNumber::first());

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-key')
            && ! $request->hasHeader('X-API-Key'));
    }

    public function test_it_uses_configured_parameter_names_and_header(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['data' => []])]);

        $this->client([
            'leads_path' => '/v2/people',
            'auth' => ['header' => 'X-Leads-Key'],
            'pagination' => ['page_param' => 'p', 'page_size_param' => 'per_page', 'page_size' => 25],
        ])->fetchPage(new PageNumber(2));

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::BASE.'/v2/people?p=2&per_page=25'
            && $request->hasHeader('X-Leads-Key', 'secret-key'));
    }

    public function test_no_auth_header_is_sent_without_a_key(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['data' => []])]);

        $this->client(['api_key' => null])->fetchPage(PageNumber::first());

        Http::assertSent(static fn (Request $request): bool => ! $request->hasHeader('X-API-Key') && ! $request->hasHeader('Authorization'));
    }

    public function test_a_401_becomes_a_non_retryable_exception_with_the_api_message(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Missing or invalid API token'], 401)]);

        $e = $this->fetchFailure(new PageNumber(2));

        $this->assertSame(401, $e->status);
        $this->assertSame(2, $e->page);
        $this->assertFalse($e->isRetryable());
        $this->assertTrue($e->isUnauthorized());
        $this->assertStringContainsString('Missing or invalid API token', $e->getMessage());
    }

    public function test_a_503_is_retryable(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Database is initializing'], 503)]);

        $e = $this->fetchFailure(PageNumber::first());

        $this->assertSame(503, $e->status);
        $this->assertTrue($e->isRetryable());
        $this->assertStringContainsString('Database is initializing', $e->getMessage());
    }

    public function test_a_429_carries_retry_after_seconds(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '7'])]);

        $e = $this->fetchFailure(PageNumber::first());

        $this->assertTrue($e->isRateLimited());
        $this->assertSame(7, $e->retryAfterSeconds);
    }

    public function test_a_retry_after_http_date_is_converted_to_seconds(): void
    {
        $at = gmdate('D, d M Y H:i:s', time() + 30).' GMT';
        Http::fake([self::BASE.'/*' => Http::response('', 429, ['Retry-After' => $at])]);

        $retryAfter = $this->fetchFailure(PageNumber::first())->retryAfterSeconds;

        $this->assertNotNull($retryAfter);
        $this->assertGreaterThanOrEqual(28, $retryAfter);
        $this->assertLessThanOrEqual(30, $retryAfter);
    }

    public function test_a_non_json_error_body_falls_back_to_the_reason_phrase(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('<html>oops</html>', 500)]);

        $e = $this->fetchFailure(PageNumber::first());

        $this->assertSame(500, $e->status);
        $this->assertStringContainsString('Internal Server Error', $e->getMessage());
    }

    public function test_a_connection_failure_is_retryable_and_has_no_status(): void
    {
        Http::fake([self::BASE.'/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

        $e = $this->fetchFailure(new PageNumber(4));

        $this->assertNull($e->status);
        $this->assertTrue($e->isRetryable());
        $this->assertStringContainsString('timed out', $e->getMessage());
    }

    public function test_a_success_that_is_not_json_is_an_invalid_response(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('not json', 200)]);

        $e = $this->fetchFailure(PageNumber::first());

        $this->assertFalse($e->isRetryable());
        $this->assertStringContainsString('invalid response', $e->getMessage());
    }

    public function test_a_success_without_a_data_list_is_an_invalid_response(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'hello'], 200)]);

        $this->assertFalse($this->fetchFailure(PageNumber::first())->isRetryable());
    }

    public function test_fetch_page_makes_a_single_attempt(): void
    {
        Http::fake([self::BASE.'/*' => Http::response([], 503)]);

        $this->fetchFailure(PageNumber::first());

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_fetch_page_does_not_touch_the_rate_limiter(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['data' => []])]);
        $limiter = new FakeRateLimiter;

        $this->client(limiter: $limiter)->fetchPage(PageNumber::first());

        $this->assertSame(0, $limiter->attempts);
    }

    // ---- logging -----------------------------------------------------------------

    public function test_every_attempt_is_logged_with_page_status_and_duration(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(Fixture::json('api/leads-page.json'))]);

        $this->client()->fetchPage(new PageNumber(5));

        $logs = $this->logger->contexts('leadscaptain.api_request');
        $this->assertCount(1, $logs);
        $this->assertSame('leads', $logs[0]['endpoint']);
        $this->assertSame(5, $logs[0]['page']);
        $this->assertSame(1, $logs[0]['attempt']);
        $this->assertSame(200, $logs[0]['status']);
        $this->assertSame('ok', $logs[0]['outcome']);
        $this->assertSame(1, $logs[0]['records']);
        $this->assertIsFloat($logs[0]['duration_ms']);
        $this->assertSame('info', $this->logger->records[0]['level']);
    }

    public function test_failures_are_logged_as_warning_or_error(): void
    {
        Http::fake([
            self::BASE.'/api/v1/leads?page=1*' => Http::response([], 503),
            self::BASE.'/api/v1/leads?page=2*' => Http::response(['message' => 'Missing or invalid API token'], 401),
        ]);

        $this->fetchFailure(PageNumber::first());
        $this->fetchFailure(new PageNumber(2));

        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame('retryable', $this->logger->records[0]['context']['outcome']);
        $this->assertSame('error', $this->logger->records[1]['level']);
        $this->assertSame('failed', $this->logger->records[1]['context']['outcome']);
        $this->assertStringContainsString('Missing or invalid API token', $this->logger->records[1]['context']['error']);
    }

    public function test_the_api_key_is_never_logged(): void
    {
        Http::fake([self::BASE.'/*' => Http::response([], 500)]);

        $this->fetchFailure(PageNumber::first());

        $this->assertStringNotContainsString('secret-key', (string) json_encode($this->logger->records));
    }

    // ---- fetchPages (concurrent) -------------------------------------------------

    public function test_fetch_pages_requests_all_pages_concurrently_and_keys_results_by_page(): void
    {
        Http::fake(fn (Request $request) => Http::response(self::page($this->pageOf($request))));

        $results = $this->client()->fetchPages(new PageNumber(4), new PageNumber(2), new PageNumber(3));

        $this->assertSame([2, 3, 4], array_keys($results));
        $this->assertContainsOnlyInstancesOf(RawPage::class, $results);
        $this->assertSame(3, $results[3]->page->value);
        Http::assertSentCount(3);
    }

    public function test_fetch_pages_returns_failures_per_page_without_throwing(): void
    {
        Http::fake(fn (Request $request) => $this->pageOf($request) === 3
            ? Http::response(['message' => 'Missing or invalid API token'], 401)
            : Http::response(self::page($this->pageOf($request))));

        $results = $this->client()->fetchPages(new PageNumber(2), new PageNumber(3));

        $this->assertInstanceOf(RawPage::class, $results[2]);
        $this->assertInstanceOf(PageFetchFailure::class, $results[3]);
        $this->assertSame(401, $results[3]->exception->status);
        Http::assertSentCount(2);
    }

    public function test_fetch_pages_retries_retryable_failures_with_backoff(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $page = $this->pageOf($request);
            $calls[$page] = ($calls[$page] ?? 0) + 1;

            return $page === 3 && $calls[$page] === 1
                ? Http::response(['message' => 'Database is initializing'], 503)
                : Http::response(self::page($page));
        });

        $results = $this->client()->fetchPages(new PageNumber(2), new PageNumber(3));

        $this->assertContainsOnlyInstancesOf(RawPage::class, $results);
        $this->assertSame([2 => 1, 3 => 2], $calls);
        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);

        $attempts = array_column($this->logger->contexts('leadscaptain.api_request'), 'attempt', 'page');
        $this->assertSame(2, $attempts[3]);
    }

    public function test_fetch_pages_gives_up_after_the_configured_retries(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Internal Server Error'], 500)]);

        $results = $this->client()->fetchPages(new PageNumber(2));

        $this->assertInstanceOf(PageFetchFailure::class, $results[2]);
        Http::assertSentCount(4);

        $last = $this->logger->records[array_key_last($this->logger->records)];
        $this->assertSame('error', $last['level']);
        $this->assertSame('gave_up', $last['context']['outcome']);
        $this->assertSame(4, $last['context']['attempt']);

        Sleep::assertSequence([
            Sleep::for(1000)->milliseconds(),
            Sleep::for(5000)->milliseconds(),
            Sleep::for(30000)->milliseconds(),
        ]);
    }

    public function test_fetch_pages_waits_for_retry_after_on_429(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? Http::response(['message' => 'Too Many Requests'], 429, ['Retry-After' => '3'])
                : Http::response(self::page($this->pageOf($request)));
        });

        $results = $this->client()->fetchPages(new PageNumber(2));

        $this->assertInstanceOf(RawPage::class, $results[2]);
        Sleep::assertSequence([Sleep::for(3000)->milliseconds()]);
    }

    public function test_fetch_pages_retries_connection_failures(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? Http::failedConnection('Connection refused')
                : Http::response(self::page($this->pageOf($request)));
        });

        $results = $this->client()->fetchPages(new PageNumber(2));

        $this->assertInstanceOf(RawPage::class, $results[2]);
        $this->assertSame(2, $calls);
    }

    public function test_fetch_pages_takes_one_rate_limit_slot_per_request_and_waits_when_denied(): void
    {
        Http::fake(fn (Request $request) => Http::response(self::page($this->pageOf($request))));
        $limiter = new FakeRateLimiter(denials: 1, waitSeconds: 2);

        $this->client(limiter: $limiter)->fetchPages(new PageNumber(2), new PageNumber(3));

        $this->assertSame(3, $limiter->attempts);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_fetch_pages_with_no_pages_sends_nothing(): void
    {
        Http::fake();

        $this->assertSame([], $this->client()->fetchPages());
        Http::assertNothingSent();
    }

    // ---- countLeads --------------------------------------------------------------

    public function test_count_leads_reads_the_count_endpoint(): void
    {
        Http::fake([self::BASE.'/api/v1/leads/count' => Http::response(['count' => 1234])]);

        $this->assertSame(1234, $this->client()->countLeads());

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::BASE.'/api/v1/leads/count'
            && $request->hasHeader('X-API-Key', 'secret-key'));
    }

    public function test_count_leads_accepts_a_total_field(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['total' => 55])]);

        $this->assertSame(55, $this->client()->countLeads());
    }

    public function test_count_leads_returns_null_on_error_status(): void
    {
        Http::fake([self::BASE.'/*' => Http::response([], 500)]);

        $this->assertNull($this->client()->countLeads());
    }

    public function test_count_leads_returns_null_on_connection_failure(): void
    {
        Http::fake([self::BASE.'/*' => Http::failedConnection()]);

        $this->assertNull($this->client()->countLeads());
    }

    public function test_count_leads_returns_null_for_an_unexpected_body(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['count' => 'many'])]);

        $this->assertNull($this->client()->countLeads());
        $this->assertSame('count', $this->logger->contexts('leadscaptain.api_request')[0]['endpoint']);
    }

    // ---- helpers -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function client(array $overrides = [], ?RequestRateLimiter $limiter = null): LeadscaptainHttpClient
    {
        /** @var array<string, mixed> $config */
        $config = array_replace_recursive(
            (array) config('leadscaptain'),
            ['base_url' => self::BASE, 'api_key' => 'secret-key'],
            $overrides,
        );

        return new LeadscaptainHttpClient(
            $this->app->make(Factory::class),
            ApiSettings::fromConfig($config),
            RetryPolicy::fromConfig((array) $config['retry']),
            $this->logger,
            $limiter,
        );
    }

    private function fetchFailure(PageNumber $page): LeadsApiException
    {
        try {
            $this->client()->fetchPage($page);
        } catch (LeadsApiException $e) {
            return $e;
        }

        $this->fail('Expected a LeadsApiException.');
    }

    private function pageOf(Request $request): int
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (int) ($query['page'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(int $page): array
    {
        return [
            'data' => [['id' => $page * 100, 'first_name' => 'Lead', 'last_name' => (string) $page]],
            'pagination' => ['page' => $page, 'limit' => 100, 'total' => 1000, 'total_pages' => 10],
        ];
    }
}
