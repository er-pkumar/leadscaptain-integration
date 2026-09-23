<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Persistence;

use Leadscaptain\Application\Contract\LeadQuery;
use Leadscaptain\Application\Dto\LeadListPage;

/**
 * Read-side LeadQuery on the leads table, ordered by id (insertion order).
 */
final readonly class EloquentLeadQuery implements LeadQuery
{
    public function page(int $page, int $perPage): LeadListPage
    {
        $rows = LeadModel::query()
            ->orderBy('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->all();

        $leads = array_values(array_map(static fn (LeadModel $row) => $row->toLead(), $rows));

        return new LeadListPage($leads, $page, $perPage, LeadModel::query()->count());
    }
}
