<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Service;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\PageImportResult;
use Leadscaptain\Application\Dto\RangeImportResult;
use Leadscaptain\Application\UseCase\ImportLeadPage;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

/**
 * Imports pages in-process, fetching up to `concurrency` pages at a time.
 * Used by the --now path and when the last page is unknown.
 *
 * With a known last page it imports the whole range. Without one it
 * keeps going until a page is empty or short, never past max pages.
 * A window with a failed page stops the import; retrying is left to the
 * API client and to the caller.
 */
final readonly class ConcurrentPageImporter
{
    public function __construct(
        private LeadsApiClient $api,
        private ImportLeadPage $importPage,
    ) {}

    public function import(SyncId $syncId, PageNumber $from, ?int $lastPage, SyncSettings $settings): RangeImportResult
    {
        $limit = min($lastPage ?? $settings->maxPages, $settings->maxPages);
        $pages = [];
        $failures = [];
        $next = $from->value;

        while ($next <= $limit) {
            $window = array_map(
                static fn (int $page): PageNumber => new PageNumber($page),
                range($next, min($next + $settings->concurrency - 1, $limit)),
            );
            $next += count($window);

            $endFound = false;

            foreach ($this->api->fetchPages(...$window) as $result) {
                if ($result instanceof PageFetchFailure) {
                    $failures[] = $result;

                    continue;
                }

                $imported = $this->importPage->importFetched($syncId, $result);
                $pages[] = $imported;
                $endFound = $endFound || $this->isLastPage($imported, $settings);
            }

            if ($failures !== []) {
                return new RangeImportResult($pages, $failures, reachedEnd: false);
            }

            if ($lastPage === null && $endFound) {
                return new RangeImportResult($pages, [], reachedEnd: true);
            }
        }

        return new RangeImportResult($pages, [], reachedEnd: $lastPage !== null && $lastPage <= $settings->maxPages);
    }

    private function isLastPage(PageImportResult $page, SyncSettings $settings): bool
    {
        return $page->isShorterThan($settings->pageSize);
    }
}
