<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;

/**
 * Summary of an in-process sync (SyncLeadsImmediately).
 */
final readonly class SyncReport
{
    /**
     * @param  list<int>  $failedPages
     */
    public function __construct(
        public SyncId $syncId,
        public SyncStatus $status,
        public LastPageStrategy $strategy,
        public ?int $lastPage,
        public int $pagesImported,
        public int $leadsImported,
        public int $recordsSkipped,
        public array $failedPages = [],
        public ?string $failureReason = null,
    ) {}
}
