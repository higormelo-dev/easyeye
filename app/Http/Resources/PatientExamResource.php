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
                'code'        => $this->code,
                'archive'     => $this->archive,
                'archive_url' => $this->archive_url,
                'name'        => $this->name,
                'active'      => (bool) $this->active,
                'created_at'  => $this->created_at,
                'updated_at'  => $this->updated_at,
            ],
            'relationships' => [
                'patient' => [
                    'id'          => $this->patient->id,
                    'entity_id'   => $this->patient->entity_id,
                    'person_id'   => $this->patient->person_id,
                    'covenant_id' => $this->patient->covenant_id,
                    'skin_id'     => $this->patient->skin_id,
                    'iris_id'     => $this->patient->iris_id,
                    'code'        => $this->patient->code,
                    'card_number' => $this->patient->card_number,
                    'active'      => (bool) $this->patient->active,
                    'created_at'  => $this->patient->created_at,
                    'updated_at'  => $this->patient->updated_at,
                ],
                'doctor'   => [],
                'schedule' => [],
            ],
        ];

        if ($this->doctor) {
            $data['relationships']['doctor']['id']               = $this->doctor->id;
            $data['relationships']['doctor']['entity_id']        = $this->doctor->entity_id;
            $data['relationships']['doctor']['person_id']        = $this->doctor->person_id;
            $data['relationships']['doctor']['user_id']          = $this->doctor->user->id;
            $data['relationships']['doctor']['code']             = $this->doctor->code;
            $data['relationships']['doctor']['record']           = $this->doctor->record;
            $data['relationships']['doctor']['record_specialty'] = $this->doctor->record_specialty;
            $data['relationships']['doctor']['color']            = $this->doctor->color;
            $data['relationships']['doctor']['partner']          = $this->doctor->partner;
            $data['relationships']['doctor']['observation']      = $this->doctor->observation;
            $data['relationships']['doctor']['active']           = $this->doctor->active;
            $data['relationships']['doctor']['created_at']       = $this->doctor->created_at;
            $data['relationships']['doctor']['updated_at']       = $this->doctor->updated_at;
        }

        if ($this->schedule) {
            $data['relationships']['schedule']['id']          = $this->schedule->id;
            $data['relationships']['schedule']['entity_id']   = $this->schedule->entity_id;
            $data['relationships']['schedule']['doctor_id']   = $this->schedule->doctor_id;
            $data['relationships']['schedule']['patient_id']  = $this->schedule->patient_id;
            $data['relationships']['schedule']['date']        = $this->schedule->date;
            $data['relationships']['schedule']['start_time']  = $this->schedule->start_time;
            $data['relationships']['schedule']['end_time']    = $this->schedule->end_time;
            $data['relationships']['schedule']['status']      = $this->schedule->status;
            $data['relationships']['schedule']['observation'] = $this->schedule->observation;
            $data['relationships']['schedule']['created_at']  = $this->schedule->created_at;
            $data['relationships']['schedule']['updated_at']  = $this->schedule->updated_at;
        }

        return $data;
    }
}
