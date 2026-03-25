<?php

namespace App\Http\Requests;

use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required',
                'uuid',
                function ($attribute, $value, $fail) {
                    $exists = \Illuminate\Support\Facades\DB::table('doctors')
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
                        ? $this->route('schedule')
                        : null;

                    $errors = app(ScheduleService::class)->validateSlot(
                        $doctorId,
                        Carbon::parse($value),
                        $excludeId,
                        (array) $this->input('resource_ids', [])
                    );

                    if (! empty($errors)) {
                        $fail($errors[0]);
                    }
                },
            ],
            'telephone'           => ['nullable', 'string', 'max:20'],
            'cellphone'           => ['nullable', 'string', 'max:20'],
            'cellphone_whatsapp'  => ['nullable', 'boolean'],
            'situation'           => ['nullable', 'integer'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'waiting_list_id' => ['nullable', 'uuid', 'exists:waiting_list,id'],
            'recurrence_type'  => ['nullable', 'string', 'in:weekly,monthly'],
            'recurrence_until' => ['nullable', 'date', 'after:date_time'],
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

    protected function prepareForValidation(): void
    {
        foreach (['telephone', 'cellphone'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $this->merge([$field => preg_replace('/\D/', '', $this->input($field))]);
            }
        }

        if ($this->has('full_name')) {
            $this->merge([
                'full_name' => mb_strtoupper($this->input('full_name')),
            ]);
        }
    }
}
