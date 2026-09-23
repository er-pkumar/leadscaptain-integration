<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\UseCase;

use Leadscaptain\Application\Dto\PaginationMeta;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Application\UseCase\ImportLeadPage;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Domain\Sync\Event\LeadsPageImported;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use Leadscaptain\Tests\Support\InMemoryLeadRepository;
use Leadscaptain\Tests\Support\RecordingEventPublisher;
use PHPUnit\Framework\TestCase;

final class ImportLeadPageTest extends TestCase
{
    private FakeLeadsApiClient $api;

    private InMemoryLeadRepository $leads;

    private RecordingEventPublisher $events;

    private ImportLeadPage $useCase;

    protected function setUp(): void
    {
        $this->api = (new FakeLeadsApiClient(pageSize: 10))->withLeads(25);
        $this->leads = new InMemoryLeadRepository;
        $this->events = new RecordingEventPublisher;
        $this->useCase = new ImportLeadPage($this->api, new LeadMapper, $this->leads, $this->events);
    }

    public function test_it_fetches_maps_and_stores_one_page(): void
    {
        $result = $this->useCase->execute(new SyncId('s1'), new PageNumber(2));

        $this->assertSame([2], $this->api->requestedPages);
        $this->assertSame(10, $this->leads->count());
        $this->assertNotNull($this->leads->findByProfileKey(new ProfileKey('11')));
        $this->assertSame(2, $result->page->value);
        $this->assertSame(10, $result->recordCount);
        $this->assertSame(10, $result->importedCount);
        $this->assertSame(0, $result->skippedCount);
    }

    public function test_it_publishes_a_page_imported_event(): void
    {
        $this->useCase->execute(new SyncId('s1'), new PageNumber(3));

        $events = $this->events->ofType(LeadsPageImported::class);
        $this->assertCount(1, $events);
        $this->assertSame('s1', $events[0]->syncId->value);
        $this->assertSame(3, $events[0]->page->value);
        $this->assertSame(5, $events[0]->importedCount);
        $this->assertSame(0, $events[0]->skippedCount);
    }

    public function test_importing_the_same_page_twice_creates_no_duplicates(): void
    {
        $this->useCase->execute(new SyncId('s1'), new PageNumber(1));
        $this->useCase->execute(new SyncId('s1'), new PageNumber(1));

        $this->assertSame(10, $this->leads->count());
    }

    public function test_skipped_records_are_counted_and_not_stored(): void
    {
        $page = new RawPage(PageNumber::first(), [['id' => 1], ['email' => 'no-id@example.com'], 'garbage'], PaginationMeta::none());

        $result = $this->useCase->importFetched(new SyncId('s1'), $page);

        $this->assertSame(1, $result->importedCount);
        $this->assertSame(2, $result->skippedCount);
        $this->assertSame(3, $result->recordCount);
        $this->assertSame(2, $this->events->ofType(LeadsPageImported::class)[0]->skippedCount);
    }

    public function test_an_empty_page_stores_nothing_but_is_still_reported(): void
    {
        $result = $this->useCase->execute(new SyncId('s1'), new PageNumber(9));

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $this->leads->upsertCalls);
        $this->assertCount(1, $this->events->ofType(LeadsPageImported::class));
    }

    public function test_an_api_failure_propagates_and_nothing_is_stored(): void
    {
        $this->api->failPage(2, LeadsApiException::forPage(new PageNumber(2), 503, 'Database is initializing'));

        try {
            $this->useCase->execute(new SyncId('s1'), new PageNumber(2));
            $this->fail('Expected LeadsApiException');
        } catch (LeadsApiException $e) {
            $this->assertSame(503, $e->status);
        }

        $this->assertSame(0, $this->leads->count());
        $this->assertSame([], $this->events->events);
    }

    public function test_a_short_page_is_detected(): void
    {
        $result = $this->useCase->execute(new SyncId('s1'), new PageNumber(3));

        $this->assertTrue($result->isShorterThan(10));
        $this->assertFalse($this->useCase->execute(new SyncId('s1'), new PageNumber(1))->isShorterThan(10));
    }
}
