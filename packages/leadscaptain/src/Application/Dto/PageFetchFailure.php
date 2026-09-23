<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;

/**
 * A page that could not be fetched as part of LeadsApiClient::fetchPages().
 */
final readonly class PageFetchFailure
{
    public function __construct(
        public PageNumber $page,
        public LeadsApiException $exception,
    ) {}
}
