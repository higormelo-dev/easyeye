<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WaitingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
                        ->where('entity_users.entity_id', session('selected_entity_id'))
                        ->whereNull('doctors.deleted_at')
                        ->exists();

                    if (! $exists) {
                        $fail('Médico não encontrado nesta clínica.');
                    }
                },
            ],
            'patient_id' => [
                'nullable',
                'uuid',
                Rule::exists('patients', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('entity_id', session('selected_entity_id'))
                            ->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'full_name'           => ['required', 'string', 'max:255'],
            'telephone'           => ['nullable', 'string', 'max:20'],
            'cellphone'           => ['nullable', 'string', 'max:20'],
            'cellphone_whatsapp'  => ['nullable', 'boolean'],
            'covenant_id'         => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('entity_id', session('selected_entity_id'))
                            ->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'visit_id' => [
                'nullable',
                'uuid',
                Rule::exists('visit_types', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('entity_id', session('selected_entity_id'))
                            ->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'notes'                => ['nullable', 'string', 'max:2000'],
            'preferred_date_from'  => ['nullable', 'date'],
            'preferred_date_until' => ['nullable', 'date', 'after_or_equal:preferred_date_from'],
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
            $this->merge(['full_name' => mb_strtoupper($this->input('full_name'))]);
        }
    }
}
