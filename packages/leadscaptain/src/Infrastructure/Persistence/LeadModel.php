<?php

declare(strict_types=1);

namespace Leadscaptain\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Eloquent row for a stored lead. Only the Infrastructure layer uses it;
 * the rest of the package works with Domain\Lead\Lead.
 *
 * The source record lives in `raw_attributes` because `attributes` would
 * clash with Eloquent's own $attributes property.
 *
 * @property int $id
 * @property string $profile_key
 * @property ?string $full_name
 * @property ?string $email
 * @property ?string $position_title
 * @property ?string $company_name
 * @property ?string $industry
 * @property ?string $location
 * @property ?string $country_code
 * @property ?array<string, mixed> $raw_attributes
 * @property ?Carbon $last_synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class LeadModel extends Model
{
    public const string TABLE = 'leadscaptain_leads';

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_attributes' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
