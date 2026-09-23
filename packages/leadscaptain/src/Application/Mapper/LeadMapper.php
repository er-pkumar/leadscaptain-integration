<?php

declare(strict_types=1);

namespace Leadscaptain\Application\Mapper;

use Leadscaptain\Application\Dto\MappedLeads;
use Leadscaptain\Application\Dto\RawPage;
use Leadscaptain\Application\Dto\SkippedRecord;
use Leadscaptain\Domain\Lead\Exception\InvalidLeadData;
use Leadscaptain\Domain\Lead\Lead;
use Leadscaptain\Domain\Lead\LeadCollection;
use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;

/**
 * Maps raw API records to Leads.
 *
 * The first alias in each list is the field name from the documented
 * response; the rest are fallbacks until the live shape is confirmed.
 * Records without a usable key are skipped; an invalid email or country
 * code is dropped but the lead is kept. The full record is always kept in
 * Lead::$attributes (email_status, first_name, last_name, ...).
 */
final readonly class LeadMapper
{
    public const array ALIASES = [
        'profile_key' => ['id', 'profile_key', 'PROFILE_KEY'],
        'full_name' => ['full_name', 'name'],
        'first_name' => ['first_name'],
        'last_name' => ['last_name'],
        'email' => ['email', 'email_address'],
        'position_title' => ['position_title', 'title', 'job_title'],
        'company_name' => ['company_name', 'company'],
        'industry' => ['industry_name', 'industry'],
        'location' => ['location', 'city'],
        'country_code' => ['country_code', 'country'],
    ];

    public function map(RawPage $page): MappedLeads
    {
        $leads = [];
        $skipped = [];

        foreach ($page->records as $index => $record) {
            if (! is_array($record) || $record === [] || array_is_list($record)) {
                $skipped[] = new SkippedRecord($index, 'record is not an object');

                continue;
            }

            /** @var array<string, mixed> $record */
            $key = self::text($record, 'profile_key', trim: false);

            if ($key === null) {
                $skipped[] = new SkippedRecord($index, 'missing lead id');

                continue;
            }

            try {
                $leads[] = new Lead(
                    profileKey: new ProfileKey($key),
                    fullName: self::fullName($record),
                    email: self::optional(static fn () => Email::fromNullable(self::text($record, 'email'))),
                    positionTitle: self::text($record, 'position_title'),
                    companyName: self::text($record, 'company_name'),
                    industry: self::text($record, 'industry'),
                    location: self::text($record, 'location'),
                    countryCode: self::optional(static fn () => CountryCode::fromNullable(self::text($record, 'country_code'))),
                    attributes: $record,
                );
            } catch (InvalidLeadData $e) {
                $skipped[] = new SkippedRecord($index, $e->getMessage());
            }
        }

        return new MappedLeads(new LeadCollection(...$leads), $skipped, count($page->records));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function fullName(array $record): ?string
    {
        $fullName = self::text($record, 'full_name');

        if ($fullName !== null) {
            return $fullName;
        }

        $parts = array_filter(
            [self::text($record, 'first_name'), self::text($record, 'last_name')],
            static fn (?string $part): bool => $part !== null,
        );

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * First alias holding a string or number, as a string. Blank text is
     * returned as null when trimming.
     *
     * @param  array<string, mixed>  $record
     */
    private static function text(array $record, string $field, bool $trim = true): ?string
    {
        foreach (self::ALIASES[$field] as $alias) {
            $value = $record[$alias] ?? null;

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            if (is_string($value)) {
                if (! $trim) {
                    return $value;
                }

                $value = trim($value);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    /**
     * Runs a value-object factory and returns null if the value is invalid.
     *
     * @template T of object
     *
     * @param  callable(): ?T  $factory
     * @return ?T
     */
    private static function optional(callable $factory): ?object
    {
        try {
            return $factory();
        } catch (InvalidLeadData) {
            return null;
        }
    }
}
