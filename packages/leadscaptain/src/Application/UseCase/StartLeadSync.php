<?php

declare(strict_types=1);

namespace Leadscaptain\Application\UseCase;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Contract\DomainEventPublisher;
use Leadscaptain\Application\Contract\LeadsApiClient;
use Leadscaptain\Application\Contract\PageImportScheduler;
use Leadscaptain\Application\Dto\SyncPlan;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Application\Service\ConcurrentPageImporter;
use Leadscaptain\Application\Service\LastPageResolver;
use Leadscaptain\Domain\Sync\Event\LeadSyncCompleted;
use Leadscaptain\Domain\Sync\Event\LeadSyncFailed;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Domain\Sync\SyncId;
use Leadscaptain\Domain\Sync\SyncStatus;

/**
 * The orchestrator, run by the queued orchestrator job:
 *
 * 1. fetch and import page 1;
 * 2. work out the last page;
 * 3. schedule pages 2..N in the background (completion is reported by
 *    the scheduler's batch), or finish inline when there is one page or
 *    the last page is unknown.
 */
final readonly class StartLeadSync
{
    public function __construct(
        private LeadsApiClient $api,
        private ImportLeadPage $importPage,
        private LastPageResolver $resolver,
        private ConcurrentPageImporter $rangeImporter,
        private PageImportScheduler $scheduler,
        private DomainEventPublisher $events,
        private SyncSettings $settings,
    ) {}

    /**
     * @throws LeadsApiException when page 1 cannot be fetched, so the job can retry
     */
    public function execute(SyncId $syncId): SyncPlan
    {
        $firstPage = $this->api->fetchPage(PageNumber::first());
        $firstResult = $this->importPage->importFetched($syncId, $firstPage);
        $resolution = $this->resolver->resolve($firstPage, $this->settings);

        if ($resolution->isKnown()) {
            $remaining = $resolution->remainingPages();

            if ($remaining === []) {
                $this->events->publish(new LeadSyncCompleted($syncId, 1, $firstResult->importedCount));

                return new SyncPlan($syncId, SyncStatus::Completed, $resolution, $firstResult);
            }

            $this->scheduler->schedule($syncId, ...$remaining);

            return new SyncPlan($syncId, SyncStatus::Running, $resolution, $firstResult, scheduledPages: count($remaining));
        }

        $inline = $this->rangeImporter->import($syncId, PageNumber::first()->next(), null, $this->settings);

        if ($inline->hasFailures()) {
            $failure = $inline->failures[0];
            $this->events->publish(new LeadSyncFailed($syncId, $failure->exception->getMessage(), $failure->page));

            return new SyncPlan($syncId, SyncStatus::Failed, $resolution, $firstResult, inline: $inline);
        }

        $this->events->publish(new LeadSyncCompleted(
            $syncId,
            1 + $inline->pageCount(),
            $firstResult->importedCount + $inline->importedCount(),
        ));

        return new SyncPlan($syncId, SyncStatus::Completed, $resolution, $firstResult, inline: $inline);
    }
}
