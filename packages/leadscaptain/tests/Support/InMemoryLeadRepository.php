<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\LeadRepository;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;

/**
 * LeadRepository keyed by profile key, so upserts are idempotent like
 * the database implementation.
 */
final class InMemoryLeadRepository implements LeadRepository
{
    /** @var array<string, Lead> */
    private array $leads = [];

    public int $upsertCalls = 0;

    public function upsertMany(LeadCollection $leads): int
    {
        $this->upsertCalls++;

        foreach ($leads as $lead) {
            $this->leads[$lead->profileKey->value] = $lead;
        }

        return count($leads);
    }

    public function findByProfileKey(ProfileKey $profileKey): ?Lead
    {
        return $this->leads[$profileKey->value] ?? null;
    }

    public function count(): int
    {
        return count($this->leads);
    }
}
