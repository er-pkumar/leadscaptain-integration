<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Leadscaptain\Domain\Shared\DomainEvent;
use Leadscaptain\Domain\Sync\SyncId;

final readonly class LeadSyncCompleted implements DomainEvent
{
    public const string NAME = 'leadscaptain.lead_sync_completed';

    public function __construct(
        public SyncId $syncId,
        public int $totalPages,
        public int $totalLeads,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {
        if ($totalPages < 0 || $totalLeads < 0) {
            throw new InvalidArgumentException('Totals cannot be negative.');
        }
    }

    public function eventName(): string
    {
        return self::NAME;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): array
    {
        return [
            'sync_id' => $this->syncId->value,
            'total_pages' => $this->totalPages,
            'total_leads' => $this->totalLeads,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
