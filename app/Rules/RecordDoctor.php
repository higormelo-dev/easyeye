<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\CurrentDoctor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * `doctor_id` do prontuário (store/update):
 *  - perfil médico: só o PRÓPRIO cadastro de médico ativo (prontuário no nome
 *    de quem registra; colega recusado). Na edição, prontuário de outro
 *    médico não é alterado nem "transferido" para quem edita. Sem cadastro
 *    ativo: recusado com a mensagem de cadastro ausente;
 *  - demais perfis: qualquer médico ATIVO da própria clínica.
 */
final class RecordDoctor implements ValidationRule
{
    public function __construct(private readonly ?string $currentRecordDoctorId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (CurrentDoctor::isDoctorProfile()) {
            $own = CurrentDoctor::issuer();

            if ($own === null) {
                $fail(CurrentDoctor::missingMessage());

                return;
            }

            if ($this->currentRecordDoctorId !== null && $this->currentRecordDoctorId !== (string) $own->id) {
                $fail(__('actions.medical_records.record_other_doctor'));

                return;
            }

            if ((string) $value !== (string) $own->id) {
                $fail(__('actions.medical_records.doctor_only_own'));
            }

            return;
        }

        $exists = DB::table('doctors')
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('doctors.id', $value)
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->whereNull('doctors.deleted_at')
            ->where('doctors.active', true)
            ->exists();

        if (! $exists) {
            $fail(__('actions.medical_records.doctor_exists_validation'));
        }
    }
}
