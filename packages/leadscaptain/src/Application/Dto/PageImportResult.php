<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Sync\PageNumber;

final readonly class PageImportResult
{
    /**
     * @param  int  $recordCount  Records returned by the API
     * @param  int  $importedCount  Unique leads written
     * @param  int  $skippedCount  Records that could not become a lead
     */
    public function __construct(
        public PageNumber $page,
        public int $recordCount,
        public int $importedCount,
        public int $skippedCount,
    ) {}

    public function isEmpty(): bool
    {
        return $this->recordCount === 0;
    }

    /** A page with fewer records than a full page is the last one. */
    public function isShorterThan(int $pageSize): bool
    {
        return $this->recordCount < $pageSize;
    }
}
