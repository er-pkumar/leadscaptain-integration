<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Contract;

use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;

/**
 * Port to the Leadscaptain leads endpoint. The page size, auth and
 * transport details belong to the implementation.
 */
interface LeadsApiClient
{
    /**
     * @throws LeadsApiException
     */
    public function fetchPage(PageNumber $page): RawPage;

    /**
     * Fetches several pages concurrently. Never throws for a single page:
     * each failed page is returned as a PageFetchFailure.
     *
     * @return array<int, RawPage|PageFetchFailure> Keyed by page number
     */
    public function fetchPages(PageNumber ...$pages): array;

    /**
     * Total number of leads, or null when the count is unavailable.
     */
    public function countLeads(): ?int;
}
