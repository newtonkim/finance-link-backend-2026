<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicHolidayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->description,
            'date' => $this->holiday_date->toDateString(),
            'recurring' => $this->recurrence_type === 'yearly',
            'recurrence_type' => $this->recurrence_type,
            'created_at' => $this->created_at,
        ];
    }
}
