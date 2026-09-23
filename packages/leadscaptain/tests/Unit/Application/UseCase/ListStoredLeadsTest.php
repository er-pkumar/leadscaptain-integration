<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\UseCase;

use InvalidArgumentException;
use Leadscaptain\Application\Dto\LeadListPage;
use Leadscaptain\Application\UseCase\ListStoredLeads;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Tests\Support\InMemoryLeadQuery;
use PHPUnit\Framework\TestCase;

final class ListStoredLeadsTest extends TestCase
{
    private InMemoryLeadQuery $query;

    protected function setUp(): void
    {
        $this->query = new InMemoryLeadQuery(...array_map(
            static fn (int $i): Lead => new Lead(new ProfileKey((string) $i)),
            range(1, 45),
        ));
    }

    public function test_it_returns_the_requested_page(): void
    {
        $page = (new ListStoredLeads($this->query))->execute(page: 2, perPage: 20);

        $this->assertSame(['21', '22'], array_slice(self::keys($page), 0, 2));
        $this->assertCount(20, $page->leads);
        $this->assertSame(2, $page->page);
        $this->assertSame(20, $page->perPage);
        $this->assertSame(45, $page->total);
        $this->assertSame(3, $page->lastPage());
    }

    public function test_the_last_page_can_be_partial(): void
    {
        $page = (new ListStoredLeads($this->query))->execute(page: 3, perPage: 20);

        $this->assertCount(5, $page->leads);
        $this->assertFalse($page->hasMorePages());
    }

    public function test_a_page_past_the_end_is_empty(): void
    {
        $page = (new ListStoredLeads($this->query))->execute(page: 9, perPage: 20);

        $this->assertSame([], $page->leads);
        $this->assertSame(45, $page->total);
    }

    public function test_per_page_is_capped(): void
    {
        $page = (new ListStoredLeads($this->query))->execute(page: 1, perPage: 5000);

        $this->assertSame(ListStoredLeads::MAX_PER_PAGE, $page->perPage);
    }

    public function test_an_empty_store_has_one_empty_page(): void
    {
        $page = (new ListStoredLeads(new InMemoryLeadQuery))->execute(page: 1, perPage: 20);

        $this->assertSame([], $page->leads);
        $this->assertSame(0, $page->total);
        $this->assertSame(1, $page->lastPage());
    }

    public function test_it_rejects_a_page_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ListStoredLeads($this->query))->execute(page: 0, perPage: 20);
    }

    public function test_it_rejects_a_page_size_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ListStoredLeads($this->query))->execute(page: 1, perPage: 0);
    }

    /**
     * @return list<string>
     */
    private static function keys(LeadListPage $page): array
    {
        return array_map(static fn (Lead $lead): string => $lead->profileKey->value, $page->leads);
    }
}
