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
                'capture_id'                     => $this->capture_id,
                'entity_id'                      => $this->patient?->entity_id,
                'integrator_id'                  => $this->capture_integrator_id ?? $this->equipment?->integrator_id,
                'content_sha256'                 => $this->content_sha256,
                'content_bytes'                  => $this->content_bytes,
                'exam_id'                        => $this->exam_id,
                'session_id'                     => $this->exam_session_id,
                'availability'                   => $this->archive ? 'original_available' : 'unavailable',
                'review_state'                   => 'unknown',
                'fulfillment_state'              => 'unknown',
                'review_url'                     => '/panel/eye-images?patient_id=' . $this->patient_id . '&exam_id=' . $this->id,
                'patient_id'                     => $this->patient_id,
                'doctor_id'                      => $this->doctor_id,
                'schedule_id'                    => $this->schedule_id,
                'entity_integrator_equipment_id' => $this->entity_integrator_equipment_id,
                'code'                           => $this->code,
                'derivative_status'              => $this->derivative_status ?? 'pending', 'derivative_error_code' => $this->derivative_error_code,
                'archive'                        => $this->archive_url,
                'name'                           => $this->name,
                'laterality'                     => $this->laterality,
                'exam_performed_at'              => $this->exam_performed_at?->copy()->setTimezone(config('app.timezone'))->toIso8601String(),
                'observation'                    => $this->observation,
                'active'                         => (bool) $this->active,
                'created_at'                     => $this->created_at,
                'updated_at'                     => $this->updated_at,
            ],
        ];

        if (! $request->routeIs('*.index')) {
            // Whitelist explícita (LGPD — minimização). toArray() despejava
            // CPF/PII bruta de paciente/médico e colunas internas do agendamento.
            $data['relationships'] = [
                'patient'   => $this->relationSummary($this->patient, withPerson: true),
                'doctor'    => $this->relationSummary($this->doctor, withPerson: true),
                'schedule'  => $this->scheduleSummary($this->schedule),
                'equipment' => $this->equipmentSummary($this->equipment),
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationSummary($model, bool $withPerson = false): array
    {
        if ($model === null) {
            return [];
        }

        $summary = [
            'id'   => $model->id,
            'code' => $model->code,
        ];

        if ($withPerson) {
            $summary['full_name'] = $model->person?->full_name;
            $summary['nickname']  = $model->person?->nickname;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSummary($schedule): array
    {
        if ($schedule === null) {
            return [];
        }

        return [
            'id'        => $schedule->id,
            'code'      => $schedule->code,
            'date_time' => $schedule->date_time,
            'situation' => $schedule->situation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentSummary($equipment): array
    {
        if ($equipment === null) {
            return [];
        }

        return [
            'id'   => $equipment->id,
            'code' => $equipment->code,
            'name' => $equipment->name,
        ];
    }
}
