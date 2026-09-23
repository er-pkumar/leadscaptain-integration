<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync\Event;

use DateTimeImmutable;
use Leadscaptain\Domain\Shared\DomainEvent;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

final readonly class LeadSyncFailed implements DomainEvent
{
    public const string NAME = 'leadscaptain.lead_sync_failed';

    public function __construct(
        public SyncId $syncId,
        public string $reason,
        public ?PageNumber $failedPage = null,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {}

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
            'reason' => $this->reason,
            'failed_page' => $this->failedPage?->value,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
