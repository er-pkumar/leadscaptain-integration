<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Domain\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use Leadscaptain\Domain\Sync\Event\LeadsPageImported;
use Leadscaptain\Domain\Sync\Event\LeadSyncCompleted;
use Leadscaptain\Domain\Sync\Event\LeadSyncFailed;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use PHPUnit\Framework\TestCase;

final class DomainEventsTest extends TestCase
{
    private const string AT = '2026-09-23T10:00:00+00:00';

    public function test_page_imported_payload(): void
    {
        $event = new LeadsPageImported(new SyncId('s1'), new PageNumber(3), 98, 2, new DateTimeImmutable(self::AT));

        $this->assertSame('leadscaptain.leads_page_imported', $event->eventName());
        $this->assertSame([
            'sync_id' => 's1',
            'page' => 3,
            'imported_count' => 98,
            'skipped_count' => 2,
            'occurred_at' => self::AT,
        ], $event->payload());
    }

    public function test_page_imported_rejects_negative_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeadsPageImported(new SyncId('s1'), PageNumber::first(), -1, 0);
    }

    public function test_sync_completed_payload(): void
    {
        $event = new LeadSyncCompleted(new SyncId('s1'), 12, 1150, new DateTimeImmutable(self::AT));

        $this->assertSame('leadscaptain.lead_sync_completed', $event->eventName());
        $this->assertSame([
            'sync_id' => 's1',
            'total_pages' => 12,
            'total_leads' => 1150,
            'occurred_at' => self::AT,
        ], $event->payload());
    }

    public function test_sync_completed_rejects_negative_totals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LeadSyncCompleted(new SyncId('s1'), -1, 0);
    }

    public function test_sync_failed_payload_with_and_without_a_page(): void
    {
        $withPage = new LeadSyncFailed(new SyncId('s1'), 'HTTP 500 after 3 attempts', new PageNumber(7), new DateTimeImmutable(self::AT));
        $withoutPage = new LeadSyncFailed(new SyncId('s1'), 'Could not resolve last page');

        $this->assertSame('leadscaptain.lead_sync_failed', $withPage->eventName());
        $this->assertSame(7, $withPage->payload()['failed_page']);
        $this->assertSame(self::AT, $withPage->occurredAt()->format(DATE_ATOM));
        $this->assertNull($withoutPage->payload()['failed_page']);
    }
}
