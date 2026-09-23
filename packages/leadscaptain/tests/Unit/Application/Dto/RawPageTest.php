<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Dto;

use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Exception\LeadsApiException;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Tests\Support\Fixture;
use PHPUnit\Framework\TestCase;

final class RawPageTest extends TestCase
{
    public function test_it_reads_records_and_pagination_from_the_documented_response(): void
    {
        $page = RawPage::fromResponse(PageNumber::first(), Fixture::json('api/leads-page.json'));

        $this->assertSame(1, $page->page->value);
        $this->assertCount(1, $page);
        $this->assertFalse($page->isEmpty());
        $this->assertIsArray($page->records[0]);
        $this->assertSame(123, $page->records[0]['id']);
        $this->assertSame(1, $page->meta->lastPage);
    }

    public function test_an_empty_data_list_is_an_empty_page(): void
    {
        $page = RawPage::fromResponse(new PageNumber(5), ['data' => [], 'pagination' => ['total_pages' => 4]]);

        $this->assertTrue($page->isEmpty());
        $this->assertCount(0, $page);
        $this->assertSame(4, $page->meta->lastPage);
    }

    public function test_a_bare_list_body_is_accepted(): void
    {
        $page = RawPage::fromResponse(PageNumber::first(), [['id' => 1], ['id' => 2]]);

        $this->assertCount(2, $page);
        $this->assertNull($page->meta->lastPage);
    }

    public function test_a_body_without_a_data_list_is_invalid(): void
    {
        $this->expectException(LeadsApiException::class);
        $this->expectExceptionMessage('page 2');

        RawPage::fromResponse(new PageNumber(2), ['data' => 'nope']);
    }

    public function test_an_object_body_without_data_is_invalid(): void
    {
        $this->expectException(LeadsApiException::class);

        RawPage::fromResponse(PageNumber::first(), ['message' => 'Missing or invalid API token']);
    }
}
