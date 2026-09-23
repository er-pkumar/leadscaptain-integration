<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Integration\Persistence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadQuery;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadRepository;
use Leadscaptain\Tests\TestCase;

final class EloquentLeadQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pages_through_leads_in_insertion_order(): void
    {
        $this->store(25);

        $page = (new EloquentLeadQuery)->page(2, 10);

        $this->assertSame(
            ['11', '12', '13', '14', '15', '16', '17', '18', '19', '20'],
            array_map(static fn (Lead $lead): string => $lead->profileKey->value, $page->leads),
        );
        $this->assertSame(2, $page->page);
        $this->assertSame(10, $page->perPage);
        $this->assertSame(25, $page->total);
        $this->assertSame(3, $page->lastPage());
    }

    public function test_it_rebuilds_full_domain_leads(): void
    {
        $lead = new Lead(
            profileKey: new ProfileKey('123'),
            fullName: 'John Doe',
            email: new Email('john.doe@example.com'),
            positionTitle: 'Senior Developer',
            companyName: 'ABC Technologies',
            industry: 'Technology',
            countryCode: new CountryCode('IN'),
            attributes: ['id' => 123, 'email_status' => 'verified'],
        );
        (new EloquentLeadRepository)->upsertMany(new LeadCollection($lead));

        $found = (new EloquentLeadQuery)->page(1, 10)->leads[0];

        $this->assertSame($lead->toArray(), $found->toArray());
    }

    public function test_a_page_past_the_end_is_empty_but_keeps_the_total(): void
    {
        $this->store(3);

        $page = (new EloquentLeadQuery)->page(5, 10);

        $this->assertSame([], $page->leads);
        $this->assertSame(3, $page->total);
    }

    public function test_an_empty_table_gives_an_empty_page(): void
    {
        $page = (new EloquentLeadQuery)->page(1, 10);

        $this->assertSame([], $page->leads);
        $this->assertSame(0, $page->total);
    }

    private function store(int $count): void
    {
        (new EloquentLeadRepository)->upsertMany(new LeadCollection(...array_map(
            static fn (int $i): Lead => new Lead(new ProfileKey((string) $i), fullName: "Lead {$i}"),
            range(1, $count),
        )));
    }
}
