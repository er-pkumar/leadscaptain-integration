<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Support;

use Leadscaptain\Application\Contract\LeadQuery;
use Leadscaptain\Application\Dto\LeadListPage;
use Leadscaptain\Domain\Lead\Lead;

final class InMemoryLeadQuery implements LeadQuery
{
    /** @var list<Lead> */
    private array $leads;

    public function __construct(Lead ...$leads)
    {
        $this->leads = array_values($leads);
    }

    public function page(int $page, int $perPage): LeadListPage
    {
        return new LeadListPage(
            array_slice($this->leads, ($page - 1) * $perPage, $perPage),
            $page,
            $perPage,
            count($this->leads),
        );
    }
}
