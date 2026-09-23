<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;

/**
 * What StartLeadSync did: Running when pages were scheduled in the
 * background, Completed or Failed when everything ran inline.
 */
final readonly class SyncPlan
{
    public function __construct(
        public SyncId $syncId,
        public SyncStatus $status,
        public LastPageResolution $resolution,
        public PageImportResult $firstPage,
        public int $scheduledPages = 0,
        public ?RangeImportResult $inline = null,
    ) {}
}
