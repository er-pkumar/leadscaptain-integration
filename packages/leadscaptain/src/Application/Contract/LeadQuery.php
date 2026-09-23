<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Contract;

use Leadscaptain\Application\Dto\LeadListPage;

/**
 * Read-side port for listing stored leads, oldest first.
 */
interface LeadQuery
{
    /**
     * @param  int  $page  1 or greater
     * @param  int  $perPage  1 or greater
     */
    public function page(int $page, int $perPage): LeadListPage;
}
