<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Domain\Lead\Lead;

/**
 * One page of stored leads plus the numbers needed to paginate.
 */
final readonly class LeadListPage
{
    /**
     * @param  list<Lead>  $leads
     */
    public function __construct(
        public array $leads,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }
}
