<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Service;

use Leadscaptain\Application\Config\SyncSettings;
use Leadscaptain\Application\Dto\LastPageResolution;
use Leadscaptain\Application\Dto\LastPageStrategy;
use Leadscaptain\Application\Dto\PaginationMeta;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Service\LastPageResolver;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Tests\Support\FakeLeadsApiClient;
use PHPUnit\Framework\TestCase;

final class LastPageResolverTest extends TestCase
{
    private FakeLeadsApiClient $api;

    protected function setUp(): void
    {
        $this->api = new FakeLeadsApiClient(pageSize: 10);
    }

    public function test_it_uses_total_pages_from_the_pagination_block(): void
    {
        $resolution = $this->resolve(self::page(10, new PaginationMeta(total: 999, lastPage: 7)));

        $this->assertSame(LastPageStrategy::TotalPages, $resolution->strategy);
        $this->assertSame(7, $resolution->lastPage);
        $this->assertFalse($resolution->capped);
        $this->assertSame(0, $this->api->countCalls);
    }

    public function test_zero_total_pages_means_a_single_page(): void
    {
        $resolution = $this->resolve(self::page(0, new PaginationMeta(total: 0, lastPage: 0)));

        $this->assertSame(1, $resolution->lastPage);
    }

    public function test_it_divides_total_by_the_page_size_from_the_response(): void
    {
        $resolution = $this->resolve(self::page(10, new PaginationMeta(perPage: 20, total: 45)));

        $this->assertSame(LastPageStrategy::TotalRecords, $resolution->strategy);
        $this->assertSame(3, $resolution->lastPage);
    }

    public function test_it_falls_back_to_the_configured_page_size_for_total(): void
    {
        $resolution = $this->resolve(self::page(10, new PaginationMeta(total: 45)));

        $this->assertSame(5, $resolution->lastPage);
    }

    public function test_a_short_first_page_is_the_only_page(): void
    {
        $resolution = $this->resolve(self::page(4, PaginationMeta::none()));

        $this->assertSame(LastPageStrategy::ShortFirstPage, $resolution->strategy);
        $this->assertSame(1, $resolution->lastPage);
        $this->assertSame(0, $this->api->countCalls);
    }

    public function test_it_asks_the_count_endpoint_when_the_page_is_full(): void
    {
        $this->api->withCount(95);

        $resolution = $this->resolve(self::page(10, PaginationMeta::none()));

        $this->assertSame(LastPageStrategy::CountEndpoint, $resolution->strategy);
        $this->assertSame(10, $resolution->lastPage);
        $this->assertSame(1, $this->api->countCalls);
    }

    public function test_it_reports_unknown_when_nothing_is_available(): void
    {
        $resolution = $this->resolve(self::page(10, PaginationMeta::none()));

        $this->assertSame(LastPageStrategy::Unknown, $resolution->strategy);
        $this->assertNull($resolution->lastPage);
        $this->assertFalse($resolution->isKnown());
        $this->assertSame([], $resolution->remainingPages());
    }

    public function test_the_last_page_is_capped_by_max_pages(): void
    {
        $resolution = $this->resolve(self::page(10, new PaginationMeta(lastPage: 5000)), maxPages: 50);

        $this->assertSame(50, $resolution->lastPage);
        $this->assertTrue($resolution->capped);
    }

    public function test_remaining_pages_start_at_page_two(): void
    {
        $resolution = $this->resolve(self::page(10, new PaginationMeta(lastPage: 4)));

        $this->assertSame([2, 3, 4], array_map(static fn (PageNumber $page): int => $page->value, $resolution->remainingPages()));
    }

    public function test_a_single_page_has_no_remaining_pages(): void
    {
        $this->assertSame([], $this->resolve(self::page(3, new PaginationMeta(lastPage: 1)))->remainingPages());
    }

    private function resolve(RawPage $firstPage, int $maxPages = 1000): LastPageResolution
    {
        return (new LastPageResolver($this->api))->resolve($firstPage, new SyncSettings(pageSize: 10, maxPages: $maxPages, concurrency: 5));
    }

    private static function page(int $records, PaginationMeta $meta): RawPage
    {
        return new RawPage(PageNumber::first(), array_fill(0, $records, ['id' => 1]), $meta);
    }
}
