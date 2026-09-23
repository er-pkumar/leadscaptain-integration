<?php

declare(strict_types=1);

namespace Leadscaptain\Application\UseCase;

use Leadscaptain\Application\Contract\DomainEventPublisher;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\PageImportResult;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Domain\Lead\LeadRepository;
use Leadscaptain\Domain\Sync\Event\LeadsPageImported;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;

/**
 * Imports one page: fetch, map, upsert, publish LeadsPageImported.
 * Safe to run again for the same page (the repository upserts), so a
 * retried queue job never creates duplicates.
 */
final readonly class ImportLeadPage
{
    public function __construct(
        private LeadsApiClient $api,
        private LeadMapper $mapper,
        private LeadRepository $leads,
        private DomainEventPublisher $events,
    ) {}

    /**
     * @throws LeadsApiException when the page cannot be fetched
     */
    public function execute(SyncId $syncId, PageNumber $page): PageImportResult
    {
        return $this->importFetched($syncId, $this->api->fetchPage($page));
    }

    /**
     * Imports a page that was already fetched (page 1 by the orchestrator,
     * or a page from a concurrent fetch).
     */
    public function importFetched(SyncId $syncId, RawPage $page): PageImportResult
    {
        $mapped = $this->mapper->map($page);

        $imported = $mapped->leads->isEmpty() ? 0 : $this->leads->upsertMany($mapped->leads);

        $this->events->publish(new LeadsPageImported($syncId, $page->page, $imported, $mapped->skippedCount()));

        return new PageImportResult($page->page, $mapped->recordCount, $imported, $mapped->skippedCount());
    }
}
