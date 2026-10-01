<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\UserInvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Convite a usuário que já tem login no EasyEye: só e-mail + perfil. Nada aqui
 * consulta se o e-mail tem conta (a resposta é sempre a mesma); o limite de
 * usuários do plano é conferido antes, sem depender disso.
 */
class UserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Rota já restrita a admin (entity.role:admin).
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
            'rule'  => ['required', 'string', Rule::in(UserInvitationService::invitableRules())],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! app(UserInvitationService::class)->hasUserSlot((string) session('selected_entity_id'))) {
                $validator->errors()->add('email', __('access_control.invitation.plan_limit'));
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'email' => __('access_control.invitation.email'),
            'rule'  => __('access_control.invitation.rule'),
        ];
    }
}
