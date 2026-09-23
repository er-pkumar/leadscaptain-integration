<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Dto;

use Leadscaptain\Application\Dto\PaginationMeta;
use Leadscaptain\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationMetaTest extends TestCase
{
    public function test_it_reads_the_documented_pagination_block(): void
    {
        $meta = PaginationMeta::fromResponse(Fixture::json('api/leads-page.json'));

        $this->assertSame(1, $meta->currentPage);
        $this->assertSame(20, $meta->perPage);
        $this->assertSame(1, $meta->total);
        $this->assertSame(1, $meta->lastPage);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function aliasShapes(): iterable
    {
        yield 'laravel meta block' => [['meta' => ['current_page' => 2, 'per_page' => 50, 'total' => 120, 'last_page' => 3]]];
        yield 'top-level keys' => [['current_page' => 2, 'per_page' => 50, 'total' => 120, 'last_page' => 3]];
        yield 'camel case' => [['pagination' => ['currentPage' => 2, 'perPage' => 50, 'total' => 120, 'totalPages' => 3]]];
        yield 'numeric strings' => [['pagination' => ['page' => '2', 'limit' => '50', 'total' => '120', 'total_pages' => '3']]];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('aliasShapes')]
    public function test_it_accepts_aliases(array $body): void
    {
        $meta = PaginationMeta::fromResponse($body);

        $this->assertSame(2, $meta->currentPage);
        $this->assertSame(50, $meta->perPage);
        $this->assertSame(120, $meta->total);
        $this->assertSame(3, $meta->lastPage);
    }

    public function test_missing_or_invalid_values_become_null(): void
    {
        $meta = PaginationMeta::fromResponse(['pagination' => ['page' => 'abc', 'limit' => 0, 'total' => -1, 'total_pages' => null]]);

        $this->assertNull($meta->currentPage);
        $this->assertNull($meta->perPage);
        $this->assertNull($meta->total);
        $this->assertNull($meta->lastPage);
    }

    public function test_zero_total_and_zero_pages_are_kept(): void
    {
        $meta = PaginationMeta::fromResponse(['pagination' => ['total' => 0, 'total_pages' => 0]]);

        $this->assertSame(0, $meta->total);
        $this->assertSame(0, $meta->lastPage);
    }

    public function test_a_body_without_pagination_gives_empty_meta(): void
    {
        $this->assertEquals(PaginationMeta::none(), PaginationMeta::fromResponse(['data' => []]));
    }
}
