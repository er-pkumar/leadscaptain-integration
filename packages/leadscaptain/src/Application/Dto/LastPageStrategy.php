<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

/**
 * How the last page of a sync was worked out, in the order tried.
 */
enum LastPageStrategy: string
{
    /** pagination.total_pages (or an alias such as meta.last_page) */
    case TotalPages = 'total_pages';

    /** ceil(pagination.total / page size) */
    case TotalRecords = 'total_records';

    /** Page 1 holds fewer records than a full page */
    case ShortFirstPage = 'short_first_page';

    /** ceil(count endpoint / page size) */
    case CountEndpoint = 'count_endpoint';

    /** Nothing available: import until an empty or short page */
    case Unknown = 'unknown';
}
