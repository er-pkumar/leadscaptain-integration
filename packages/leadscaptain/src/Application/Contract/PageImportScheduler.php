<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Contract;

use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

/**
 * Port that schedules pages to be imported in the background, as one unit
 * of work per sync (a queue batch in the Laravel implementation).
 */
interface PageImportScheduler
{
    public function schedule(SyncId $syncId, PageNumber ...$pages): void;
}
