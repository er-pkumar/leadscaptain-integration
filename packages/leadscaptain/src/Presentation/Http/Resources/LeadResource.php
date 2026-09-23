<?php

declare(strict_types=1);

namespace Leadscaptain\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Leadscaptain\Domain\Lead\Lead;

/**
 * JSON shape of a stored lead. `attributes` is the full source record
 * (email_status and anything else the API sent).
 *
 * @property Lead $resource
 */
final class LeadResource extends JsonResource
{
    public function __construct(Lead $lead)
    {
        parent::__construct($lead);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lead = $this->resource;

        return [
            'profile_key' => $lead->profileKey->value,
            'full_name' => $lead->fullName,
            'email' => $lead->email?->value,
            'position_title' => $lead->positionTitle,
            'company_name' => $lead->companyName,
            'industry' => $lead->industry,
            'location' => $lead->location,
            'country_code' => $lead->countryCode?->value,
            'attributes' => $lead->attributes,
        ];
    }
}
