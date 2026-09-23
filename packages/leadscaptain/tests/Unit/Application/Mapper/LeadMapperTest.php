<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Unit\Application\Mapper;

use Leadscaptain\Application\Dto\PaginationMeta;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Mapper\LeadMapper;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Domain\Sync\PageNumber;
use Leadscaptain\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadMapperTest extends TestCase
{
    private LeadMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LeadMapper;
    }

    public function test_it_maps_the_documented_sample_record(): void
    {
        $mapped = $this->mapper->map(RawPage::fromResponse(PageNumber::first(), Fixture::json('api/leads-page.json')));

        $this->assertCount(1, $mapped->leads);
        $this->assertSame([], $mapped->skipped);

        $lead = $mapped->leads->all()[0];
        $this->assertSame('123', $lead->profileKey->value);
        $this->assertSame('John Doe', $lead->fullName);
        $this->assertSame('john.doe@example.com', $lead->email?->value);
        $this->assertSame('Senior Developer', $lead->positionTitle);
        $this->assertSame('ABC Technologies', $lead->companyName);
        $this->assertSame('Technology', $lead->industry);
        $this->assertNull($lead->location);
        $this->assertSame('IN', $lead->countryCode?->value);
        $this->assertSame('verified', $lead->attribute('email_status'));
        $this->assertSame('John', $lead->attribute('first_name'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function aliasRecords(): iterable
    {
        yield 'profile key and full name' => [[
            'PROFILE_KEY' => 'abc-1', 'full_name' => 'Jane Roe', 'email_address' => 'jane@roe.io',
            'title' => 'CTO', 'company' => 'Roe Ltd', 'industry' => 'Software', 'city' => 'Pune', 'country' => 'in',
        ]];
        yield 'snake case profile key and name' => [[
            'profile_key' => 'abc-1', 'name' => 'Jane Roe', 'email' => 'jane@roe.io',
            'job_title' => 'CTO', 'company_name' => 'Roe Ltd', 'industry_name' => 'Software', 'location' => 'Pune', 'country_code' => 'IN',
        ]];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    #[DataProvider('aliasRecords')]
    public function test_it_accepts_field_aliases(array $record): void
    {
        $lead = $this->mapper->map(self::page([$record]))->leads->all()[0];

        $this->assertSame('abc-1', $lead->profileKey->value);
        $this->assertSame('Jane Roe', $lead->fullName);
        $this->assertSame('jane@roe.io', $lead->email?->value);
        $this->assertSame('CTO', $lead->positionTitle);
        $this->assertSame('Roe Ltd', $lead->companyName);
        $this->assertSame('Software', $lead->industry);
        $this->assertSame('Pune', $lead->location);
        $this->assertSame('IN', $lead->countryCode?->value);
    }

    public function test_id_wins_over_other_key_aliases(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 7, 'profile_key' => 'other']]))->leads->all()[0];

        $this->assertSame('7', $lead->profileKey->value);
    }

    public function test_full_name_wins_over_first_and_last_name(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1, 'full_name' => 'Full', 'first_name' => 'A', 'last_name' => 'B']]))->leads->all()[0];

        $this->assertSame('Full', $lead->fullName);
    }

    public function test_a_single_name_part_is_used_alone(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1, 'first_name' => ' Ana ', 'last_name' => null]]))->leads->all()[0];

        $this->assertSame('Ana', $lead->fullName);
    }

    public function test_missing_name_parts_give_a_null_name(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1]]))->leads->all()[0];

        $this->assertNull($lead->fullName);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unusableRecords(): iterable
    {
        yield 'no key' => [['email' => 'a@b.co'], 'missing lead id'];
        yield 'null key' => [['id' => null], 'missing lead id'];
        yield 'blank key' => [['id' => '   '], 'non-empty profile key'];
        yield 'key too long' => [['id' => str_repeat('x', ProfileKey::MAX_LENGTH + 1)], 'cannot be longer'];
        yield 'array key' => [['id' => ['nested']], 'missing lead id'];
        yield 'not an object' => ['just a string', 'not an object'];
        yield 'a list instead of an object' => [[1, 2], 'not an object'];
    }

    #[DataProvider('unusableRecords')]
    public function test_records_without_a_usable_key_are_skipped(mixed $record, string $reason): void
    {
        $mapped = $this->mapper->map(self::page([['id' => 1], $record]));

        $this->assertCount(1, $mapped->leads);
        $this->assertCount(1, $mapped->skipped);
        $this->assertSame(1, $mapped->skipped[0]->index);
        $this->assertStringContainsString($reason, $mapped->skipped[0]->reason);
    }

    public function test_an_invalid_email_is_dropped_but_the_lead_is_kept(): void
    {
        $mapped = $this->mapper->map(self::page([['id' => 1, 'email' => 'not-an-email', 'first_name' => 'A']]));

        $lead = $mapped->leads->all()[0];
        $this->assertNull($lead->email);
        $this->assertSame('A', $lead->fullName);
        $this->assertSame('not-an-email', $lead->attribute('email'));
        $this->assertSame([], $mapped->skipped);
    }

    public function test_an_invalid_country_code_is_dropped_but_the_lead_is_kept(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1, 'country_code' => 'India']]))->leads->all()[0];

        $this->assertNull($lead->countryCode);
    }

    public function test_numeric_text_values_are_kept_as_strings(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1, 'company_name' => 3000]]))->leads->all()[0];

        $this->assertSame('3000', $lead->companyName);
    }

    public function test_non_scalar_text_values_are_ignored(): void
    {
        $lead = $this->mapper->map(self::page([['id' => 1, 'company_name' => ['x'], 'industry_name' => true]]))->leads->all()[0];

        $this->assertNull($lead->companyName);
        $this->assertNull($lead->industry);
    }

    public function test_duplicate_ids_in_a_page_become_one_lead(): void
    {
        $mapped = $this->mapper->map(self::page([['id' => 1, 'first_name' => 'Old'], ['id' => '1', 'first_name' => 'New']]));

        $this->assertCount(1, $mapped->leads);
        $this->assertSame('New', $mapped->leads->all()[0]->fullName);
        $this->assertSame(2, $mapped->recordCount);
    }

    public function test_an_empty_page_maps_to_nothing(): void
    {
        $mapped = $this->mapper->map(self::page([]));

        $this->assertTrue($mapped->leads->isEmpty());
        $this->assertSame([], $mapped->skipped);
        $this->assertSame(0, $mapped->recordCount);
    }

    /**
     * @param  list<mixed>  $records
     */
    private static function page(array $records): RawPage
    {
        return new RawPage(PageNumber::first(), $records, PaginationMeta::none());
    }
}
