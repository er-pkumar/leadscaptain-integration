<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

final readonly class RangeImportResult
{
    /**
     * @param  list<PageImportResult>  $pages
     * @param  list<PageFetchFailure>  $failures
     * @param  bool  $reachedEnd  False when stopped by a failure or by max pages
     */
    public function __construct(
        public array $pages,
        public array $failures,
        public bool $reachedEnd,
    ) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function importedCount(): int
    {
        return array_sum(array_map(static fn (PageImportResult $page): int => $page->importedCount, $this->pages));
    }

    public function skippedCount(): int
    {
        return array_sum(array_map(static fn (PageImportResult $page): int => $page->skippedCount, $this->pages));
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
