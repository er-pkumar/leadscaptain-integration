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
use Leadscaptain\Application\UseCase\StartLeadSync;
use Leadscaptain\Domain\Sync\Event\LeadsPageImported;
use Leadscaptain\Domain\Sync\Event\LeadSyncCompleted;
use Leadscaptain\Domain\Sync\Event\LeadSyncFailed;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use Leadscaptain\Tests\Support\InMemoryLeadRepository;
use Leadscaptain\Tests\Support\RecordingEventPublisher;
use Leadscaptain\Tests\Support\RecordingPageImportScheduler;
use PHPUnit\Framework\TestCase;

final class StartLeadSyncTest extends TestCase
{
    private FakeLeadsApiClient $api;

    private InMemoryLeadRepository $leads;

    private RecordingEventPublisher $events;

    private RecordingPageImportScheduler $scheduler;

    protected function setUp(): void
    {
        $this->api = new FakeLeadsApiClient(pageSize: 10);
        $this->leads = new InMemoryLeadRepository;
        $this->events = new RecordingEventPublisher;
        $this->scheduler = new RecordingPageImportScheduler;
    }

    public function test_it_imports_page_one_and_schedules_the_remaining_pages(): void
    {
        $this->api->withLeads(35);

        $plan = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame([1], $this->api->requestedPages);
        $this->assertSame(10, $this->leads->count());
        $this->assertSame([['sync_id' => 's1', 'pages' => [2, 3, 4]]], $this->scheduler->scheduled);
        $this->assertSame(SyncStatus::Running, $plan->status);
        $this->assertSame(LastPageStrategy::TotalPages, $plan->resolution->strategy);
        $this->assertSame(4, $plan->resolution->lastPage);
        $this->assertSame(3, $plan->scheduledPages);
        $this->assertCount(1, $this->events->ofType(LeadsPageImported::class));
        $this->assertSame([], $this->events->ofType(LeadSyncCompleted::class));
    }

    public function test_a_single_page_sync_completes_without_scheduling(): void
    {
        $this->api->withLeads(7);

        $plan = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame([], $this->scheduler->scheduled);
        $this->assertSame(SyncStatus::Completed, $plan->status);
        $this->assertSame(0, $plan->scheduledPages);

        $completed = $this->events->ofType(LeadSyncCompleted::class);
        $this->assertCount(1, $completed);
        $this->assertSame(1, $completed[0]->totalPages);
        $this->assertSame(7, $completed[0]->totalLeads);
    }

    public function test_an_empty_api_completes_with_zero_leads(): void
    {
        $plan = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(SyncStatus::Completed, $plan->status);
        $this->assertSame(0, $this->events->ofType(LeadSyncCompleted::class)[0]->totalLeads);
    }

    public function test_the_schedule_is_capped_by_max_pages(): void
    {
        $this->api->withLeads(500);

        $plan = $this->useCase(maxPages: 5)->execute(new SyncId('s1'));

        $this->assertSame([2, 3, 4, 5], $this->scheduler->scheduled[0]['pages']);
        $this->assertTrue($plan->resolution->capped);
    }

    public function test_an_unknown_last_page_is_imported_inline(): void
    {
        $this->api->withLeads(45)->withoutPagination();

        $plan = $this->useCase(concurrency: 2)->execute(new SyncId('s1'));

        $this->assertSame([], $this->scheduler->scheduled);
        $this->assertSame(LastPageStrategy::Unknown, $plan->resolution->strategy);
        $this->assertSame(SyncStatus::Completed, $plan->status);
        $this->assertSame(45, $this->leads->count());
        $this->assertNotNull($plan->inline);
        $this->assertSame(4, $plan->inline->pageCount());

        $completed = $this->events->ofType(LeadSyncCompleted::class)[0];
        $this->assertSame(5, $completed->totalPages);
        $this->assertSame(45, $completed->totalLeads);
    }

    public function test_the_count_endpoint_is_used_when_pagination_is_missing(): void
    {
        $this->api->withLeads(45)->withoutPagination()->withCount(45);

        $plan = $this->useCase()->execute(new SyncId('s1'));

        $this->assertSame(LastPageStrategy::CountEndpoint, $plan->resolution->strategy);
        $this->assertSame([2, 3, 4, 5], $this->scheduler->scheduled[0]['pages']);
    }

    public function test_an_inline_failure_fails_the_sync(): void
    {
        $this->api->withLeads(45)->withoutPagination()->failPage(3);

        $plan = $this->useCase(concurrency: 5)->execute(new SyncId('s1'));

        $this->assertSame(SyncStatus::Failed, $plan->status);
        $this->assertSame([], $this->events->ofType(LeadSyncCompleted::class));

        $failed = $this->events->ofType(LeadSyncFailed::class);
        $this->assertCount(1, $failed);
        $this->assertSame(3, $failed[0]->failedPage?->value);
        $this->assertStringContainsString('HTTP 500', $failed[0]->reason);
    }

    public function test_a_page_one_failure_propagates_so_the_job_can_retry(): void
    {
        $this->api->withLeads(35)->failPage(1, LeadsApiException::forPage(PageNumber::first(), 503, 'Database is initializing'));

        $this->expectException(LeadsApiException::class);

        try {
            $this->useCase()->execute(new SyncId('s1'));
        } finally {
            $this->assertSame([], $this->scheduler->scheduled);
            $this->assertSame([], $this->events->events);
        }
    }

    private function useCase(int $maxPages = 1000, int $concurrency = 10): StartLeadSync
    {
        $importPage = new ImportLeadPage($this->api, new LeadMapper, $this->leads, $this->events);

        return new StartLeadSync(
            $this->api,
            $importPage,
            new LastPageResolver($this->api),
            new ConcurrentPageImporter($this->api, $importPage),
            $this->scheduler,
            $this->events,
            new SyncSettings(pageSize: 10, maxPages: $maxPages, concurrency: $concurrency),
        );
    }
}
