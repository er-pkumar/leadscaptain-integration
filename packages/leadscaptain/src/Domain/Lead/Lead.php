<?php

declare(strict_types=1);

namespace Leadscaptain\Domain\Lead;

use Leadscaptain\Domain\Lead\ValueObject\CountryCode;
use Leadscaptain\Domain\Lead\ValueObject\Email;
use Leadscaptain\Domain\Lead\ValueObject\ProfileKey;

/**
 * A lead imported from Leadscaptain, identified by its profile key.
 *
 * Named fields cover what the API documents (position title, industry,
 * location, country code). The full source record is kept in
 * $attributes so nothing is lost if the API returns more fields.
 */
final readonly class Lead
{
    public ?string $fullName;

    public ?string $positionTitle;

    public ?string $companyName;

    public ?string $industry;

    public ?string $location;

    /**
     * @param  array<string, mixed>  $attributes  Raw source record
     */
    public function __construct(
        public ProfileKey $profileKey,
        ?string $fullName = null,
        public ?Email $email = null,
        ?string $positionTitle = null,
        ?string $companyName = null,
        ?string $industry = null,
        ?string $location = null,
        public ?CountryCode $countryCode = null,
        public array $attributes = [],
    ) {
        $this->fullName = self::clean($fullName);
        $this->positionTitle = self::clean($positionTitle);
        $this->companyName = self::clean($companyName);
        $this->industry = self::clean($industry);
        $this->location = self::clean($location);
    }

    public function hasSameIdentityAs(self $other): bool
    {
        return $this->profileKey->equals($other->profileKey);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array{
     *     profile_key: string,
     *     full_name: ?string,
     *     email: ?string,
     *     position_title: ?string,
     *     company_name: ?string,
     *     industry: ?string,
     *     location: ?string,
     *     country_code: ?string,
     *     attributes: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'profile_key' => $this->profileKey->value,
            'full_name' => $this->fullName,
            'email' => $this->email?->value,
            'position_title' => $this->positionTitle,
            'company_name' => $this->companyName,
            'industry' => $this->industry,
            'location' => $this->location,
            'country_code' => $this->countryCode?->value,
            'attributes' => $this->attributes,
        ];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
