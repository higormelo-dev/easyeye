<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
                    $dt    = Carbon::parse($value);
                    $query = DB::table('schedules')
                        ->where('doctor_id', $this->input('doctor_id'))
                        ->where('date_time', $dt->format('Y-m-d H:i:s'))
                        ->whereNull('deleted_at');

                    if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
                        $query->where('id', '!=', $this->route('schedule'));
                    }

                    if ($query->exists()) {
                        $fail(__('validation.custom.schedule.doctor_datetime_unique'));
                    }
                },
            ],
            'telephone'          => ['nullable', 'string', 'max:20'],
            'cellphone'          => ['nullable', 'string', 'max:20'],
            'cellphone_whatsapp' => ['nullable', 'boolean'],
            'situation'          => ['nullable', 'integer'],
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
