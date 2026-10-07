<?php

namespace App\Http\Requests;

use App\Models\VisitType;
use App\Rules\{ActiveCatalogItem, ActiveCovenant};
use App\Support\{BrazilianFormat, CurrentDoctor};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WaitingListRequest extends FormRequest
{
    /** Perfil médico sem cadastro de médico não mexe na lista de espera. */
    public function authorize(): bool
    {
        return ! CurrentDoctor::isMissing();
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(CurrentDoctor::missingMessage());
    }

    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required',
                'uuid',
                function ($attribute, $value, $fail) {
                    if (! CurrentDoctor::canAccessDoctorId((string) $value)) {
                        $fail(__('schedules.doctor_only_own'));

                        return;
                    }

                    $exists = DB::table('doctors')
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
            'full_name'          => ['required', 'string', 'max:255'],
            'telephone'          => ['nullable', 'string', 'max:20'],
            'cellphone'          => ['nullable', 'string', 'max:20'],
            'cellphone_whatsapp' => ['nullable', 'boolean'],
            'covenant_id'        => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) {
                    $query->where(function ($q) {
                        $q->where('entity_id', session('selected_entity_id'))
                            ->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
                // Entrada nova na fila (vira agendamento): só convênio ativo.
                new ActiveCovenant(),
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
                new ActiveCatalogItem(VisitType::class, null, 'validation.custom.visit_type.inactive'),
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
                $this->merge([$field => BrazilianFormat::canonicalPhone((string) $this->input($field))]);
            }
        }

        if ($this->has('full_name')) {
            $this->merge(['full_name' => mb_strtoupper($this->input('full_name'))]);
        }
    }
}
