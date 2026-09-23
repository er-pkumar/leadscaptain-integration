<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Dto;

/**
 * Pagination details from a leads response. Every field is optional
 * because the live response shape is not confirmed; the documented one is
 * {"pagination": {"page", "limit", "total", "total_pages"}}.
 */
final readonly class PaginationMeta
{
    /** Blocks searched in order; null means the top level of the body. */
    private const array BLOCKS = ['pagination', 'meta', null];

    private const array CURRENT_PAGE = ['page', 'current_page', 'currentPage'];

    private const array PER_PAGE = ['limit', 'per_page', 'perPage'];

    private const array TOTAL = ['total', 'total_count', 'totalCount'];

    private const array LAST_PAGE = ['total_pages', 'last_page', 'totalPages', 'lastPage'];

    public function __construct(
        public ?int $currentPage = null,
        public ?int $perPage = null,
        public ?int $total = null,
        public ?int $lastPage = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<mixed>  $body  Decoded response body
     */
    public static function fromResponse(array $body): self
    {
        foreach (self::BLOCKS as $block) {
            $source = $block === null ? $body : ($body[$block] ?? null);

            if (! is_array($source)) {
                continue;
            }

            $meta = new self(
                currentPage: self::int($source, self::CURRENT_PAGE, min: 1),
                perPage: self::int($source, self::PER_PAGE, min: 1),
                total: self::int($source, self::TOTAL, min: 0),
                lastPage: self::int($source, self::LAST_PAGE, min: 0),
            );

            if (! $meta->isEmpty()) {
                return $meta;
            }
        }

        return self::none();
    }

    public function isEmpty(): bool
    {
        return $this->currentPage === null
            && $this->perPage === null
            && $this->total === null
            && $this->lastPage === null;
    }

    /**
     * @param  array<mixed>  $source
     * @param  list<string>  $keys
     */
    private static function int(array $source, array $keys, int $min): ?int
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $value = (int) $value;

                return $value >= $min ? $value : null;
            }
        }

        return null;
    }
}
