<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Service;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\LastPageResolution;
use Leadscaptain\Application\Dto\LastPageStrategy;
use Leadscaptain\Application\Dto\RawPage;

/**
 * Works out the last page from page 1, trying in order:
 * total_pages → total / page size → short first page → count endpoint.
 * The result is capped by SyncSettings::$maxPages.
 */
final readonly class LastPageResolver
{
    public function __construct(private LeadsApiClient $api) {}

    public function resolve(RawPage $firstPage, SyncSettings $settings): LastPageResolution
    {
        $meta = $firstPage->meta;
        $pageSize = $meta->perPage ?? $settings->pageSize;

        if ($meta->lastPage !== null) {
            return self::capped(LastPageStrategy::TotalPages, $meta->lastPage, $settings);
        }

        if ($meta->total !== null) {
            return self::capped(LastPageStrategy::TotalRecords, self::pagesFor($meta->total, $pageSize), $settings);
        }

        if (count($firstPage) < $pageSize) {
            return self::capped(LastPageStrategy::ShortFirstPage, 1, $settings);
        }

        $count = $this->api->countLeads();

        if ($count !== null) {
            return self::capped(LastPageStrategy::CountEndpoint, self::pagesFor($count, $settings->pageSize), $settings);
        }

        return LastPageResolution::unknown();
    }

    private static function pagesFor(int $records, int $pageSize): int
    {
        return (int) ceil($records / $pageSize);
    }

    private static function capped(LastPageStrategy $strategy, int $lastPage, SyncSettings $settings): LastPageResolution
    {
        $lastPage = max(1, $lastPage);

        return new LastPageResolution(
            $strategy,
            min($lastPage, $settings->maxPages),
            capped: $lastPage > $settings->maxPages,
        );
    }
}
