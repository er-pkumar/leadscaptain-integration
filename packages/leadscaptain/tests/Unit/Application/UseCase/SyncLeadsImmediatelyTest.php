<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\UseCase;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Dto\LastPageStrategy;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Application\Service\ConcurrentPageImporter;
use Leadscaptain\Application\Service\LastPageResolver;
use Leadscaptain\Application\UseCase\ImportLeadPage;
use Leadscaptain\Application\UseCase\SyncLeadsImmediately;
use Leadscaptain\Domain\Sync\Event\LeadSyncCompleted;
use Leadscaptain\Domain\Sync\Event\LeadSyncFailed;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use Leadscaptain\Tests\Support\InMemoryLeadRepository;
use Leadscaptain\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;

final class SyncLeadsImmediatelyTest extends TestCase
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

    public function test_it_imports_every_page_in_process(): void
    {
        $this->api->withLeads(95);

        $report = $this->useCase(concurrency: 4)->execute(new SyncId('s1'));

        $this->assertSame([[2, 3, 4, 5], [6, 7, 8, 9], [10]], $this->api->concurrentBatches);
        $this->assertSame(95, $this->leads->count());
        $this->assertSame(SyncStatus::Completed, $report->status);
        $this->assertSame('s1', $report->syncId->value);
        $this->assertSame(LastPageStrategy::TotalPages, $report->strategy);
        $this->assertSame(10, $report->lastPage);
        $this->assertSame(10, $report->pagesImported);
        $this->assertSame(95, $report->leadsImported);
        $this->assertSame(0, $report->recordsSkipped);
        $this->assertSame([], $report->failedPages);
        $this->assertNull($report->failureReason);

        $completed = $this->events->ofType(LeadSyncCompleted::class)[0];
        $this->assertSame(10, $completed->totalPages);
        $this->assertSame(95, $completed->totalLeads);
    }

    public function test_it_generates_a_sync_id_when_none_is_given(): void
    {
        $report = $this->useCase()->execute();

        $this->assertNotSame('', $report->syncId->value);
    }

    public function test_an_unknown_last_page_imports_until_a_short_page(): void
    {
        $this->api->withLeads(25)->withoutPagination();

        $report = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(LastPageStrategy::Unknown, $report->strategy);
        $this->assertNull($report->lastPage);
        $this->assertSame(25, $report->leadsImported);
        $this->assertSame(SyncStatus::Completed, $report->status);
    }

    public function test_a_failed_page_fails_the_sync_and_is_reported(): void
    {
        $this->api->withLeads(50)->failPage(4, LeadsApiException::forPage(new PageNumber(4), 503, 'Database is initializing'));

        $report = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(SyncStatus::Failed, $report->status);
        $this->assertSame([4], $report->failedPages);
        $this->assertStringContainsString('Database is initializing', (string) $report->failureReason);
        $this->assertSame([], $this->events->ofType(LeadSyncCompleted::class));
        $this->assertSame(4, $this->events->ofType(LeadSyncFailed::class)[0]->failedPage?->value);
    }

    public function test_a_page_one_failure_is_reported_without_throwing(): void
    {
        $this->api->withLeads(50)->failPage(1, LeadsApiException::forPage(PageNumber::first(), 401, 'Missing or invalid API token'));

        $report = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(SyncStatus::Failed, $report->status);
        $this->assertSame([1], $report->failedPages);
        $this->assertSame(0, $report->pagesImported);
        $this->assertSame([1], $this->api->requestedPages);
        $this->assertCount(1, $this->events->ofType(LeadSyncFailed::class));
    }

    public function test_skipped_records_are_totalled(): void
    {
        $this->api->withLeads(5);

        $report = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(0, $report->recordsSkipped);
        $this->assertSame(1, $report->pagesImported);
    }

    private function useCase(int $concurrency = 10): SyncLeadsImmediately
    {
        $importPage = new ImportLeadPage($this->api, new LeadMapper, $this->leads, $this->events);

        return new SyncLeadsImmediately(
            $this->api,
            $importPage,
            new LastPageResolver($this->api),
            new ConcurrentPageImporter($this->api, $importPage),
            $this->events,
            new SyncSettings(pageSize: 10, maxPages: 1000, concurrency: $concurrency),
        );
    }
}
