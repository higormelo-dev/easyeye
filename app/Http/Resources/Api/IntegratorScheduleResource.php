<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegratorScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['type' => 'schedule', 'id' => $this->id, 'attributes' => ['clinic_date' => $this->date_time?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d'), 'entity_id' => $this->entity_id, 'patient_id' => $this->patient_id, 'doctor_id' => $this->doctor_id, 'code' => $this->code, 'import_code' => $this->import_code, 'full_name' => $this->full_name, 'date_time' => $this->date_time, 'local_date_time' => $this->date_time?->copy()->setTimezone(config('app.timezone'))->toIso8601String(), 'patient_birth_date' => $this->patient?->person?->birth_date?->format('Y-m-d'), 'patient_sex' => match((string) ($this->patient?->person?->gender ?? '')) {
            '0'                                                                         => 'F','1' => 'M',default => null
        }, 'clinic_resource_ids' => $this->resources->pluck('id')->all(), 'situation' => $this->situation, 'active' => (bool) $this->active, 'fulfillment' => 'unknown', 'created_at' => $this->created_at, 'updated_at' => $this->updated_at]];
    }
}
