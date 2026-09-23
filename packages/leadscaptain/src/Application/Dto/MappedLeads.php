<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Lead\LeadCollection;

/**
 * Result of mapping one page: the unique leads plus the records skipped.
 */
final readonly class MappedLeads
{
    /**
     * @param  list<SkippedRecord>  $skipped
     */
    public function __construct(
        public LeadCollection $leads,
        public array $skipped,
        public int $recordCount,
    ) {}

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
