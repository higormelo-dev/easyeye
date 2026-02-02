<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientExamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'type'       => 'patient_exams',
            'id'         => $this->id,
            'attributes' => [
                'patient_id'  => $this->patient_id,
                'doctor_id'   => $this->doctor_id,
                'schedule_id' => $this->schedule_id,
                'code'        => $this->code,
                'archive'     => $this->archive_url,
                'name'        => $this->name,
                'active'      => (bool) $this->active,
                'created_at'  => $this->created_at,
                'updated_at'  => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'patient'  => $this->patient?->toArray() ?? (object) [],
                'doctor'   => $this->doctor?->toArray() ?? (object) [],
                'schedule' => $this->schedule?->toArray() ?? (object) [],
            ];
        }

        return $data;
    }
}
