<?php

namespace App\Http\Requests;

use App\Enums\{MedicalSpecialty, ScheduleAttendanceType, ScheduleSituation};
use App\Models\Schedule;
use App\Services\ScheduleService;
use App\Support\BrazilianFormat;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required',
                'uuid',
                function ($attribute, $value, $fail) {
                    $exists = DB::table('doctors')
                        ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                        ->where('doctors.id', $value)
                        ->where('entity_users.entity_id', session()->get('selected_entity_id'))
                        ->whereNull('doctors.deleted_at')
                        ->exists();

                    if (! $exists) {
                        $fail(__('validation.custom.schedule.doctor_not_found'));
                    }
                },
            ],
            'patient_id' => [
                'nullable',
                'uuid',
                Rule::exists('patients', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'covenant_id' => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'visit_id' => [
                'nullable',
                'uuid',
                Rule::exists('visit_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'date_time' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $doctorId = $this->input('doctor_id');

                    if (! $doctorId || ! Str::isUuid($doctorId)) {
                        return; // doctor_id validation will already report the error
                    }

                    $excludeId = ($this->isMethod('PUT') || $this->isMethod('PATCH'))
                        ? $this->routeScheduleId()
                        : null;

                    $errors = app(ScheduleService::class)->validateSlot(
                        $doctorId,
                        Carbon::parse($value),
                        $excludeId,
                        (array) $this->input('resource_ids', []),
                    );

                    if (! empty($errors)) {
                        $fail($errors[0]);
                    }
                },
            ],
            'attendance_type'     => ['nullable', Rule::enum(ScheduleAttendanceType::class)],
            'specialty_area'      => ['nullable', Rule::enum(MedicalSpecialty::class)],
            'telephone'           => ['nullable', 'string', 'max:20'],
            'cellphone'           => ['nullable', 'string', 'max:20'],
            'cellphone_whatsapp'  => ['nullable', 'boolean'],
            'situation'           => ['nullable', 'integer', Rule::enum(ScheduleSituation::class)], // aplicado pelo fluxo, nunca direto — ver scheduleAttributes()
            'notes'               => ['nullable', 'string', 'max:2000'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'waiting_list_id'     => ['nullable', 'uuid', 'exists:waiting_list,id'],
            'recurrence_type'     => ['nullable', 'string', 'in:weekly,monthly'],
            'recurrence_until'    => ['nullable', 'date', 'after:date_time'],
            'resource_ids'        => ['nullable', 'array'],
            'resource_ids.*'      => [
                'uuid',
                Rule::exists('clinic_resources', 'id')->where(function ($query) {
                    $query->where('entity_id', session()->get('selected_entity_id'))
                        ->where('active', true)
                        ->whereNull('deleted_at');
                }),
            ],
        ];
    }

    /**
     * Campos gravados direto no agendamento (mass assignment). `situation`
     * é aceito (compatibilidade) mas fica de fora: o controller aplica via
     * ScheduleService::changeSituation() — histórico, timestamps e a trava
     * "Atendido exige caixa" — ver requestedSituation().
     *
     * @return array<string, mixed>
     */
    public function scheduleAttributes(): array
    {
        return $this->safe()->except(['situation']);
    }

    /** Situação pedida no formulário, a aplicar pelo fluxo (null = não mexe). */
    public function requestedSituation(): ?ScheduleSituation
    {
        $value = $this->validated('situation');

        return $value === null ? null : ScheduleSituation::from((int) $value);
    }

    /**
     * Id do agendamento em edição. O route model binding (SubstituteBindings)
     * roda antes do FormRequest, então route('schedule') já é o MODEL — antes
     * ele ia cru para validateSlot() e virava o JSON do model como bind de
     * UUID (500 "invalid input syntax for type uuid" em todo PUT).
     */
    private function routeScheduleId(): ?string
    {
        $schedule = $this->route('schedule');

        if ($schedule instanceof Schedule) {
            return (string) $schedule->getKey();
        }

        return is_string($schedule) ? $schedule : null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['telephone', 'cellphone'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => BrazilianFormat::canonicalPhone((string) $this->input($field))]);
            }
        }

        if ($this->has('full_name')) {
            $this->merge([
                'full_name' => mb_strtoupper($this->input('full_name')),
            ]);
        }
    }
}
