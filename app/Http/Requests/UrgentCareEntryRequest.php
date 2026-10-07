<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\{UrgentCarePriority, UrgentCareReason};
use App\Models\{Doctor, UrgentCareEntry};
use App\Rules\ActiveCovenant;
use App\Services\UrgentCare\UrgentCareService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Entrada/edição de item do pronto atendimento. Paciente (só no cadastro) e
 * médico preferido sempre da clínica da sessão; convênio só ativo (o que o
 * item já tem passa); "Outro" exige a descrição do motivo.
 */
class UrgentCareEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');
        $entry    = $this->routeEntry();

        return [
            'patient_id' => $entry
                ? ['prohibited']
                : [
                    'required',
                    'uuid',
                    Rule::exists('patients', 'id')
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at'),
                ],
            'reason'         => ['required', 'integer', Rule::enum(UrgentCareReason::class)],
            'reason_details' => [
                'nullable',
                'string',
                'max:500',
                Rule::requiredIf(fn () => (int) $this->input('reason') === UrgentCareReason::Other->value),
            ],
            'priority'            => ['required', 'integer', Rule::enum(UrgentCarePriority::class)],
            'preferred_doctor_id' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($entityId): void {
                    $exists = Doctor::query()
                        ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                        ->where('entity_users.entity_id', $entityId)
                        ->where('doctors.active', true)
                        ->whereKey($value)
                        ->exists();

                    if (! $exists) {
                        $fail(__('urgent_care.invalid_doctor'));
                    }
                },
            ],
            'covenant_id' => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(fn ($q) => $q
                    ->where(fn ($own) => $own->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
                new ActiveCovenant($entry?->covenant_id ? (string) $entry->covenant_id : null),
            ],
        ];
    }

    /**
     * Paciente já na fila de hoje: recusa (a recepção ajusta o item existente).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->routeEntry() || $validator->errors()->has('patient_id')) {
                return;
            }

            if (app(UrgentCareService::class)->isQueued((string) session('selected_entity_id'), (string) $this->input('patient_id'))) {
                $validator->errors()->add('patient_id', __('urgent_care.already_queued'));
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'patient_id'          => __('urgent_care.field_patient'),
            'reason'              => __('urgent_care.field_reason'),
            'reason_details'      => __('urgent_care.field_reason_details'),
            'priority'            => __('urgent_care.field_priority'),
            'preferred_doctor_id' => __('urgent_care.field_doctor'),
            'covenant_id'         => __('urgent_care.field_covenant'),
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['preferred_doctor_id', 'covenant_id', 'reason_details'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    private function routeEntry(): ?UrgentCareEntry
    {
        $entry = $this->route('urgentCareEntry');

        return $entry instanceof UrgentCareEntry ? $entry : null;
    }
}
