<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Sync\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Leadscaptain\Domain\Shared\DomainEvent;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

final readonly class LeadsPageImported implements DomainEvent
{
    public const string NAME = 'leadscaptain.leads_page_imported';

    public function __construct(
        public SyncId $syncId,
        public PageNumber $page,
        public int $importedCount,
        public int $skippedCount,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable,
    ) {
        if ($importedCount < 0 || $skippedCount < 0) {
            throw new InvalidArgumentException('Imported and skipped counts cannot be negative.');
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
            'page' => $this->page->value,
            'imported_count' => $this->importedCount,
            'skipped_count' => $this->skippedCount,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
