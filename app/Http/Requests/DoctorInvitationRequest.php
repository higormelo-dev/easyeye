<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\DoctorInvitationService;
use Illuminate\Validation\Validator;

/**
 * Convite a médico que já tem login no EasyEye: mesmos campos do cadastro de
 * médico (viram o cadastro PRÓPRIO desta clínica no aceite), sem senha — o
 * login é o dele. A detecção é refeita aqui no servidor (nunca confia no
 * front): só convida se houver exatamente um login (de outra clínica) e vaga
 * de médico no plano.
 */
class DoctorInvitationRequest extends DoctorRequest
{
    /** Login existente do médico convidado (resolvido em after()). */
    public ?User $invitedUser = null;

    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['password', 'password_confirmation']));
    }

    protected function handleExistingLogin(Validator $validator, array $detection): void
    {
        $service = app(DoctorInvitationService::class);

        match ($detection['status']) {
            DoctorInvitationService::DETECT_INVITE => $service->hasDoctorSlot((string) session('selected_entity_id'))
                ? $this->invitedUser = $detection['user']
                : $validator->errors()->add('existing_doctor', __('doctors.invitation.plan_limit')),
            DoctorInvitationService::DETECT_MEMBER   => $this->addDuplicateError($validator, (string) $detection['field']),
            DoctorInvitationService::DETECT_TAKEN    => $this->addDuplicateError($validator, (string) $detection['field']),
            DoctorInvitationService::DETECT_CONFLICT => $this->addConflictError($validator),
            default                                  => $validator->errors()->add('existing_doctor', __('doctors.invitation.not_invitable')),
        };
    }

    /**
     * Dados que a clínica digitou (sem senha), guardados criptografados no
     * convite até o aceite.
     *
     * @return array<string, mixed>
     */
    public function invitationPayload(): array
    {
        return array_diff_key($this->validated(), array_flip(['password', 'password_confirmation']));
    }
}
