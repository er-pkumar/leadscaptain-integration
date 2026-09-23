<?php

declare(strict_types=1);

namespace Leadscaptain\Tests\Integration\Persistence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;
use Leadscaptain\Infrastructure\Persistence\EloquentLeadRepository;
use Leadscaptain\Infrastructure\Persistence\LeadModel;
use Leadscaptain\Tests\TestCase;

/**
 * Runs on the configured database: SQLite in memory by default, MySQL
 * when DB_CONNECTION=mysql is set (CI and the local db container).
 */
final class EloquentLeadRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentLeadRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentLeadRepository;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_migration_creates_the_leads_table(): void
    {
        $this->assertTrue(Schema::hasTable('leadscaptain_leads'));
        $this->assertTrue(Schema::hasColumns('leadscaptain_leads', [
            'id', 'profile_key', 'full_name', 'email', 'position_title', 'company_name',
            'industry', 'location', 'country_code', 'raw_attributes', 'last_synced_at',
            'created_at', 'updated_at',
        ]));
    }

    public function test_it_inserts_new_leads_with_every_field(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');

        $written = $this->repository->upsertMany(new LeadCollection(self::jane()));

        $this->assertSame(1, $written);

        $row = LeadModel::query()->sole();
        $this->assertSame('123', $row->profile_key);
        $this->assertSame('Jane Doe', $row->full_name);
        $this->assertSame('jane@example.com', $row->email);
        $this->assertSame('CTO', $row->position_title);
        $this->assertSame('Acme', $row->company_name);
        $this->assertSame('Technology', $row->industry);
        $this->assertSame('Pune', $row->location);
        $this->assertSame('IN', $row->country_code);
        $this->assertSame(['id' => 123, 'email_status' => 'verified'], $row->raw_attributes);
        $this->assertSame('2026-09-24 10:00:00', $row->last_synced_at?->format('Y-m-d H:i:s'));
    }

    public function test_optional_fields_are_stored_as_null(): void
    {
        $this->repository->upsertMany(new LeadCollection(new Lead(new ProfileKey('k1'))));

        $row = LeadModel::query()->sole();
        $this->assertNull($row->full_name);
        $this->assertNull($row->email);
        $this->assertNull($row->country_code);
        $this->assertSame([], $row->raw_attributes);
    }

    public function test_an_existing_lead_is_updated_not_duplicated(): void
    {
        $this->repository->upsertMany(new LeadCollection(self::jane()));
        $this->repository->upsertMany(new LeadCollection(self::jane(fullName: 'Jane Updated', company: 'Globex')));

        $this->assertSame(1, LeadModel::query()->count());
        $row = LeadModel::query()->sole();
        $this->assertSame('Jane Updated', $row->full_name);
        $this->assertSame('Globex', $row->company_name);
    }

    public function test_saving_the_same_collection_twice_is_idempotent(): void
    {
        $leads = new LeadCollection(self::jane(), self::lead('2'), self::lead('3'));

        $this->repository->upsertMany($leads);
        $before = LeadModel::query()->orderBy('profile_key')->get(['profile_key', 'full_name', 'email'])->toArray();

        $this->repository->upsertMany($leads);
        $after = LeadModel::query()->orderBy('profile_key')->get(['profile_key', 'full_name', 'email'])->toArray();

        $this->assertSame(3, LeadModel::query()->count());
        $this->assertSame($before, $after);
    }

    public function test_an_update_keeps_created_at_and_moves_updated_and_synced_times(): void
    {
        Carbon::setTestNow('2026-09-24 10:00:00');
        $this->repository->upsertMany(new LeadCollection(self::jane()));

        Carbon::setTestNow('2026-09-25 12:30:00');
        $this->repository->upsertMany(new LeadCollection(self::jane(fullName: 'Jane Updated')));

        $row = LeadModel::query()->sole();
        $this->assertSame('2026-09-24 10:00:00', $row->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-25 12:30:00', $row->updated_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-25 12:30:00', $row->last_synced_at?->format('Y-m-d H:i:s'));
    }

    public function test_large_collections_are_written_in_chunks(): void
    {
        $leads = new LeadCollection(...array_map(static fn (int $i): Lead => self::lead((string) $i), range(1, 1250)));
        $upserts = 0;
        DB::listen(static function ($query) use (&$upserts): void {
            if (str_contains(strtolower($query->sql), 'insert into')) {
                $upserts++;
            }
        });

        $written = (new EloquentLeadRepository(chunkSize: 500))->upsertMany($leads);

        $this->assertSame(1250, $written);
        $this->assertSame(1250, LeadModel::query()->count());
        $this->assertSame(3, $upserts);
    }

    public function test_an_empty_collection_writes_nothing(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->assertSame(0, $this->repository->upsertMany(LeadCollection::empty()));
        $this->assertSame(0, $queries);
    }

    public function test_text_longer_than_the_column_is_truncated_but_kept_in_raw_attributes(): void
    {
        $long = str_repeat('é', 300);

        $this->repository->upsertMany(new LeadCollection(new Lead(new ProfileKey('k1'), companyName: $long, attributes: ['company_name' => $long])));

        $row = LeadModel::query()->sole();
        $this->assertSame(255, mb_strlen((string) $row->company_name));
        $this->assertSame($long, $row->raw_attributes['company_name']);
    }

    public function test_unicode_and_numeric_looking_keys_round_trip(): void
    {
        $lead = new Lead(new ProfileKey('0045'), fullName: 'Zoë Ångström 李', attributes: ['note' => 'emoji 🚀']);

        $this->repository->upsertMany(new LeadCollection($lead));

        $found = $this->repository->findByProfileKey(new ProfileKey('0045'));
        $this->assertNotNull($found);
        $this->assertSame('0045', $found->profileKey->value);
        $this->assertSame('Zoë Ångström 李', $found->fullName);
        $this->assertSame('emoji 🚀', $found->attribute('note'));
    }

    public function test_find_by_profile_key_rebuilds_the_domain_lead(): void
    {
        $this->repository->upsertMany(new LeadCollection(self::jane()));

        $found = $this->repository->findByProfileKey(new ProfileKey('123'));

        $this->assertNotNull($found);
        $this->assertSame(self::jane()->toArray(), $found->toArray());
    }

    public function test_find_by_profile_key_returns_null_when_missing(): void
    {
        $this->assertNull($this->repository->findByProfileKey(new ProfileKey('nope')));
    }

    public function test_count_returns_the_number_of_stored_leads(): void
    {
        $this->assertSame(0, $this->repository->count());

        $this->repository->upsertMany(new LeadCollection(self::lead('a'), self::lead('b')));

        $this->assertSame(2, $this->repository->count());
    }

    private static function jane(string $fullName = 'Jane Doe', string $company = 'Acme'): Lead
    {
        return new Lead(
            profileKey: new ProfileKey('123'),
            fullName: $fullName,
            email: new Email('jane@example.com'),
            positionTitle: 'CTO',
            companyName: $company,
            industry: 'Technology',
            location: 'Pune',
            countryCode: new CountryCode('IN'),
            attributes: ['id' => 123, 'email_status' => 'verified'],
        );
    }

    private static function lead(string $key): Lead
    {
        return new Lead(new ProfileKey($key), fullName: "Lead {$key}", email: new Email("lead{$key}@example.com"));
    }
}
