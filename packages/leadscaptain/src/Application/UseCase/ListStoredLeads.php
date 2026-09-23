<?php

declare(strict_types=1);

namespace Leadscaptain\Application\UseCase;

use InvalidArgumentException;
use Leadscaptain\Application\Contract\LeadQuery;
use Leadscaptain\Application\Dto\LeadListPage;

/**
 * Lists stored leads page by page (backs GET /api/leadscaptain/leads).
 */
final readonly class ListStoredLeads
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(private LeadQuery $leads) {}

    public function execute(int $page = 1, int $perPage = 20): LeadListPage
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException("Page and page size must be 1 or greater, got page {$page}, size {$perPage}.");
        }

        return $this->leads->page($page, min($perPage, self::MAX_PER_PAGE));
    }
}
