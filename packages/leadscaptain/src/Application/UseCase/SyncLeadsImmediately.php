<?php

declare(strict_types=1);

namespace Leadscaptain\Application\UseCase;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Contract\DomainEventPublisher;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Dto\LastPageStrategy;
use Leadscaptain\Application\Dto\PageFetchFailure;
use Leadscaptain\Application\Dto\SyncReport;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Service\ConcurrentPageImporter;
use Leadscaptain\Application\Service\LastPageResolver;
use Leadscaptain\Domain\Sync\Event\LeadSyncCompleted;
use Leadscaptain\Domain\Sync\Event\LeadSyncFailed;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;

/**
 * Runs a full sync in the current process, without the queue
 * (`leadscaptain:sync --now`). Failures are reported, not thrown.
 */
final readonly class SyncLeadsImmediately
{
    public function __construct(
        private LeadsApiClient $api,
        private ImportLeadPage $importPage,
        private LastPageResolver $resolver,
        private ConcurrentPageImporter $rangeImporter,
        private DomainEventPublisher $events,
        private SyncSettings $settings,
    ) {}

    public function execute(?SyncId $syncId = null): SyncReport
    {
        $syncId ??= SyncId::generate();

        try {
            $firstPage = $this->api->fetchPage(PageNumber::first());
        } catch (LeadsApiException $e) {
            $this->events->publish(new LeadSyncFailed($syncId, $e->getMessage(), PageNumber::first()));

            return new SyncReport($syncId, SyncStatus::Failed, LastPageStrategy::Unknown, null, 0, 0, 0, [1], $e->getMessage());
        }

        $firstResult = $this->importPage->importFetched($syncId, $firstPage);
        $resolution = $this->resolver->resolve($firstPage, $this->settings);
        $range = $this->rangeImporter->import($syncId, PageNumber::first()->next(), $resolution->lastPage, $this->settings);

        $pages = 1 + $range->pageCount();
        $imported = $firstResult->importedCount + $range->importedCount();
        $skipped = $firstResult->skippedCount + $range->skippedCount();

        if ($range->hasFailures()) {
            $failure = $range->failures[0];
            $this->events->publish(new LeadSyncFailed($syncId, $failure->exception->getMessage(), $failure->page));

            return new SyncReport(
                $syncId,
                SyncStatus::Failed,
                $resolution->strategy,
                $resolution->lastPage,
                $pages,
                $imported,
                $skipped,
                array_map(static fn (PageFetchFailure $f): int => $f->page->value, $range->failures),
                $failure->exception->getMessage(),
            );
        }

        $this->events->publish(new LeadSyncCompleted($syncId, $pages, $imported));

        return new SyncReport($syncId, SyncStatus::Completed, $resolution->strategy, $resolution->lastPage, $pages, $imported, $skipped);
    }
}
