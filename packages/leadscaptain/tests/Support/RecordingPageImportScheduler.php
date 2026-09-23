<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Application\Contract\PageImportScheduler;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

final class RecordingPageImportScheduler implements PageImportScheduler
{
    /** @var list<array{sync_id: string, pages: list<int>}> */
    public array $scheduled = [];

    public function schedule(SyncId $syncId, PageNumber ...$pages): void
    {
        $this->scheduled[] = [
            'sync_id' => $syncId->value,
            'pages' => array_map(static fn (PageNumber $page): int => $page->value, array_values($pages)),
        ];
    }
}
