<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

use Countable;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;

/**
 * One page of the leads endpoint, before mapping. Records stay raw so
 * LeadMapper can decide what to keep.
 */
final readonly class RawPage implements Countable
{
    /** Keys that may hold the list of records, in order. */
    private const array DATA_KEYS = ['data', 'leads', 'items'];

    /**
     * @param  list<mixed>  $records
     */
    public function __construct(
        public PageNumber $page,
        public array $records,
        public PaginationMeta $meta,
    ) {}

    /**
     * @param  array<mixed>  $body  Decoded response body
     *
     * @throws LeadsApiException when the body holds no list of records
     */
    public static function fromResponse(PageNumber $page, array $body): self
    {
        if (array_is_list($body)) {
            return new self($page, $body, PaginationMeta::none());
        }

        foreach (self::DATA_KEYS as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
                return new self($page, $body[$key], PaginationMeta::fromResponse($body));
            }
        }

        throw LeadsApiException::invalidResponse($page, 'no list of leads under "data"', 200);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function count(): int
    {
        return count($this->records);
    }
}
