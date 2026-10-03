<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type'       => 'schedule',
            'id'         => $this->id,
            'attributes' => [
                'clinic_resource_ids' => $this->resources->pluck('id')->all(),
                'patient_birth_date'  => $this->patient?->person?->birth_date?->format('Y-m-d'),
                'patient_sex'         => match ((string) ($this->patient?->person?->gender ?? '')) {
                    '0' => 'F', '1' => 'M', default => null,
                },
                'local_date_time'    => $this->date_time?->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
                'fulfillment'        => 'unknown',
                'entity_id'          => $this->entity_id,
                'doctor_id'          => $this->doctor_id,
                'patient_id'         => $this->patient_id,
                'covenant_id'        => $this->covenant_id,
                'visit_id'           => $this->visit_id,
                'code'               => $this->code,
                'import_code'        => $this->import_code,
                'full_name'          => $this->full_name,
                'date_time'          => $this->date_time,
                'telephone'          => $this->telephone,
                'cellphone'          => $this->cellphone,
                'cellphone_whatsapp' => $this->cellphone_whatsapp,
                'situation'          => $this->situation,
                'active'             => (bool) $this->active,
                'created_at'         => $this->created_at,
                'updated_at'         => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'entity'     => $this->entity?->toArray() ?? (object) [],
                'doctor'     => $this->doctor?->toArray() ?? (object) [],
                'patient'    => $this->patient?->toArray() ?? (object) [],
                'covenant'   => $this->covenant?->toArray() ?? (object) [],
                'visit_type' => $this->visitType?->toArray() ?? (object) [],
            ];
        }

        return $data;
    }
}
