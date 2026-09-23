<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Service;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Application\Service\ConcurrentPageImporter;
use Leadscaptain\Application\UseCase\ImportLeadPage;
use Leadscaptain\Domain\Sync\Event\LeadsPageImported;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use Leadscaptain\Tests\Support\InMemoryLeadRepository;
use Leadscaptain\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;

final class ConcurrentPageImporterTest extends TestCase
{
    private FakeLeadsApiClient $api;

    private InMemoryLeadRepository $leads;

    private RecordingEventPublisher $events;

    protected function setUp(): void
    {
        $this->api = new FakeLeadsApiClient(pageSize: 10);
        $this->leads = new InMemoryLeadRepository;
        $this->events = new RecordingEventPublisher;
    }

    public function test_a_known_range_is_fetched_in_windows_of_the_concurrency_limit(): void
    {
        $this->api->withLeads(70);

        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: 7, settings: self::settings(concurrency: 4));

        $this->assertSame([[2, 3, 4, 5], [6, 7]], $this->api->concurrentBatches);
        $this->assertSame(6, $result->pageCount());
        $this->assertSame(60, $result->importedCount());
        $this->assertSame(60, $this->leads->count());
        $this->assertTrue($result->reachedEnd);
        $this->assertFalse($result->hasFailures());
    }

    public function test_an_unknown_range_stops_after_a_short_page(): void
    {
        $this->api->withLeads(45);

        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: null, settings: self::settings(concurrency: 2));

        $this->assertSame([[2, 3], [4, 5]], $this->api->concurrentBatches);
        $this->assertSame(35, $result->importedCount());
        $this->assertTrue($result->reachedEnd);
    }

    public function test_an_unknown_range_stops_at_an_empty_page(): void
    {
        $this->api->withLeads(40);

        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: null, settings: self::settings(concurrency: 3));

        $this->assertSame([[2, 3, 4], [5, 6, 7]], $this->api->concurrentBatches);
        $this->assertSame(30, $result->importedCount());
        $this->assertTrue($result->reachedEnd);
    }

    public function test_an_unknown_range_never_goes_past_max_pages(): void
    {
        $this->api->withLeads(1000);

        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: null, settings: self::settings(concurrency: 3, maxPages: 5));

        $this->assertSame([[2, 3, 4], [5]], $this->api->concurrentBatches);
        $this->assertFalse($result->reachedEnd);
    }

    public function test_failed_pages_are_reported_and_no_further_window_is_started(): void
    {
        $this->api->withLeads(100)->failPage(3, LeadsApiException::forPage(new PageNumber(3), 500, 'Internal Server Error'));

        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: 10, settings: self::settings(concurrency: 3));

        $this->assertSame([[2, 3, 4]], $this->api->concurrentBatches);
        $this->assertTrue($result->hasFailures());
        $this->assertSame(3, $result->failures[0]->page->value);
        $this->assertSame(2, $result->pageCount());
        $this->assertFalse($result->reachedEnd);
    }

    public function test_every_imported_page_publishes_an_event(): void
    {
        $this->api->withLeads(30);

        $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: 3, settings: self::settings(concurrency: 5));

        $this->assertCount(2, $this->events->ofType(LeadsPageImported::class));
    }

    public function test_an_empty_known_range_does_nothing(): void
    {
        $result = $this->importer()->import(new SyncId('s1'), new PageNumber(2), lastPage: 1, settings: self::settings());

        $this->assertSame([], $this->api->concurrentBatches);
        $this->assertSame(0, $result->pageCount());
        $this->assertTrue($result->reachedEnd);
    }

    private function importer(): ConcurrentPageImporter
    {
        return new ConcurrentPageImporter($this->api, new ImportLeadPage($this->api, new LeadMapper, $this->leads, $this->events));
    }

    private static function settings(int $concurrency = 10, int $maxPages = 1000): SyncSettings
    {
        return new SyncSettings(pageSize: 10, maxPages: $maxPages, concurrency: $concurrency);
    }
}
