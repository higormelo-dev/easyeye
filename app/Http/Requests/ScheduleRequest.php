<?php

namespace App\Http\Requests;

use App\Enums\{MedicalSpecialty, ScheduleAttendanceType, ScheduleSituation};
use App\Models\{Schedule, VisitType};
use App\Rules\{ActiveCatalogItem, ActiveCovenant};
use App\Services\{ScheduleSeriesService, ScheduleService};
use App\Support\{BrazilianFormat, CurrentDoctor};
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
                    // Perfil médico: sem cadastro de médico não agenda; com
                    // cadastro, só para si (App\Support\CurrentDoctor).
                    if (CurrentDoctor::isMissing()) {
                        $fail(CurrentDoctor::missingMessage());

                        return;
                    }

                    if (! CurrentDoctor::canAccessDoctorId((string) $value)) {
                        $fail(__('schedules.doctor_only_own'));

                        return;
                    }

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
                // Agendamento novo / troca de convênio: só ativo. O convênio que
                // o agendamento em edição já tem passa (não trava a recepção).
                new ActiveCovenant($this->routeSchedule()?->covenant_id),
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
                // Mesma regra do convênio: tipo de consulta inativo (a tela
                // esconde) só passa se já era o do agendamento em edição.
                new ActiveCatalogItem(VisitType::class, $this->routeSchedule()?->visit_id, 'validation.custom.visit_type.inactive'),
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

                    // Atendimento do pronto atendimento (fora da grade): a
                    // recepção completa convênio/observações sem mexer no
                    // médico/horário — isso não passa pela regra da grade
                    // (o horário é o do "Atender", fora do intervalo/escala).
                    $current = $this->routeSchedule();

                    if ($current?->isUrgentCare()
                        && (string) $current->doctor_id === (string) $doctorId
                        && $current->date_time->equalTo(Carbon::parse($value))) {
                        return;
                    }

                    // Encaixe: pode cair em horário ocupado/fora do intervalo,
                    // mas respeita expediente, bloqueio, sala e o teto do turno.
                    $errors = app(ScheduleService::class)->validateFor(
                        $this->boolean('is_fit_in'),
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
            'is_fit_in'           => ['nullable', 'boolean'],
            // Série de sessões (só no cadastro): as datas 2..N escolhidas na
            // prévia (a 1ª é o próprio date_time). Cada uma é validada como o
            // horário principal em ScheduleSeriesService — tudo ou nada.
            'series_dates' => $this->isMethod('POST')
                ? ['nullable', 'array', 'max:' . (ScheduleSeriesService::MAX_SESSIONS - 1)]
                : ['prohibited'],
            'series_dates.*' => ['required', 'date', 'distinct', 'after:date_time'],
            'resource_ids'   => ['nullable', 'array'],
            'resource_ids.*' => [
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
        return $this->safe()->except(['situation', 'series_dates']);
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

    /** Agendamento em edição (PUT/PATCH); null no cadastro. */
    private function routeSchedule(): ?Schedule
    {
        if (! $this->isMethod('PUT') && ! $this->isMethod('PATCH')) {
            return null;
        }

        $schedule = $this->route('schedule');

        if ($schedule instanceof Schedule) {
            return $schedule;
        }

        return is_string($schedule) && Str::isUuid($schedule) ? Schedule::query()->find($schedule) : null;
    }

    protected function prepareForValidation(): void
    {
        // Encaixe: na edição sem o campo, vale o que o agendamento já é (um
        // PUT parcial não transforma encaixe em horário normal por omissão).
        if (! $this->has('is_fit_in') || $this->input('is_fit_in') === null) {
            $this->merge(['is_fit_in' => (bool) $this->routeSchedule()?->is_fit_in]);
        }

        // Atendimento do pronto atendimento nunca vira encaixe (não entra no
        // teto do turno).
        if ($this->routeSchedule()?->isUrgentCare()) {
            $this->merge(['is_fit_in' => false]);
        }

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
