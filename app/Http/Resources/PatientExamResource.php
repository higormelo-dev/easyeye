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
                'code'       => $this->code,
                'archive'    => $this->archive_url,
                'name'       => $this->name,
                'active'     => (bool) $this->active,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            $data['relationships'] = [
                'patient'  => $this->patient->toArray(),
                'doctor'   => $this->doctor ? $this->doctor->toArray() : [],
                'schedule' => $this->schedule ? $this->schedule->toArray() : [],
            ];
        }

        return $data;
    }
}
