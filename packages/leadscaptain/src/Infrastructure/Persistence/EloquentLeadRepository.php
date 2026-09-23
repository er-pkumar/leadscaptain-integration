<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Persistence;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\LeadRepository;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;

/**
 * LeadRepository on Eloquent. upsertMany() is one INSERT ... ON CONFLICT /
 * ON DUPLICATE KEY UPDATE per chunk, keyed on the unique profile_key, so
 * re-importing a page updates rows instead of duplicating them. All
 * chunks of one call run in a single transaction.
 */
final readonly class EloquentLeadRepository implements LeadRepository
{
    public const int CHUNK_SIZE = 500;

    /** Column length for the string columns (Blueprint::string default). */
    private const int TEXT_LENGTH = 255;

    /** Columns refreshed when a lead already exists (created_at is kept). */
    private const array UPDATE_COLUMNS = [
        'full_name', 'email', 'position_title', 'company_name', 'industry',
        'location', 'country_code', 'raw_attributes', 'last_synced_at',
    ];

    public function __construct(private int $chunkSize = self::CHUNK_SIZE) {}

    public function upsertMany(LeadCollection $leads): int
    {
        if ($leads->isEmpty()) {
            return 0;
        }

        $syncedAt = Date::now();
        $model = new LeadModel;

        $model->getConnection()->transaction(function () use ($leads, $syncedAt): void {
            foreach (array_chunk($leads->all(), max(1, $this->chunkSize)) as $chunk) {
                LeadModel::query()->upsert(
                    array_map(static fn (Lead $lead): array => self::row($lead, $syncedAt), $chunk),
                    ['profile_key'],
                    self::UPDATE_COLUMNS,
                );
            }
        });

        return count($leads);
    }

    public function findByProfileKey(ProfileKey $profileKey): ?Lead
    {
        $row = LeadModel::query()->where('profile_key', $profileKey->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function count(): int
    {
        return LeadModel::query()->count();
    }

    /**
     * upsert() bypasses model casts, so JSON is encoded here.
     *
     * @return array<string, mixed>
     */
    private static function row(Lead $lead, DateTimeInterface $syncedAt): array
    {
        return [
            'profile_key' => $lead->profileKey->value,
            'full_name' => self::fit($lead->fullName),
            'email' => self::fit($lead->email?->value),
            'position_title' => self::fit($lead->positionTitle),
            'company_name' => self::fit($lead->companyName),
            'industry' => self::fit($lead->industry),
            'location' => self::fit($lead->location),
            'country_code' => $lead->countryCode?->value,
            'raw_attributes' => json_encode(
                $lead->attributes,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            'last_synced_at' => $syncedAt,
        ];
    }

    /**
     * Truncates to the column length so one long value cannot fail the
     * whole page on strict databases; the full value stays in raw_attributes.
     */
    private static function fit(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::TEXT_LENGTH);
    }

    private static function toDomain(LeadModel $row): Lead
    {
        return new Lead(
            profileKey: new ProfileKey($row->profile_key),
            fullName: $row->full_name,
            email: Email::fromNullable($row->email),
            positionTitle: $row->position_title,
            companyName: $row->company_name,
            industry: $row->industry,
            location: $row->location,
            countryCode: CountryCode::fromNullable($row->country_code),
            attributes: $row->raw_attributes ?? [],
        );
    }
}
