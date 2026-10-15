<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

/**
 * Configuração geral do recall da clínica. `doctor_order_enabled` é obrigatório; os campos do convite por WhatsApp
 * são opcionais (quem manda só o primeiro continua funcionando) e, enviados, valem como um conjunto coerente: a
 * janela termina depois de começar, o intervalo mínimo respeita o piso (`recall.invites.min_days_between_invites_floor`)
 * e o teto por ciclo e o teto diário têm limites que protegem o número de WhatsApp.
 */
class RecallGeneralSettingsRequest extends RecallSettingsRequest
{
    public function rules(): array
    {
        return [
            'doctor_order_enabled'         => ['required', 'boolean'],
            'whatsapp_invites_enabled'     => ['sometimes', 'boolean'],
            'invite_window_start'          => ['sometimes', 'required', 'date_format:H:i'],
            'invite_window_end'            => ['sometimes', 'required', 'date_format:H:i', 'after:invite_window_start'],
            'invite_weekdays'              => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'invite_weekdays.*'            => ['integer', 'between:1,7', 'distinct'],
            'min_days_between_invites'     => ['sometimes', 'required', 'integer', 'min:' . (int) config('recall.invites.min_days_between_invites_floor', 7), 'max:365'],
            'max_auto_invites_per_cycle'   => ['sometimes', 'required', 'integer', 'between:1,10'],
            'daily_invite_cap'             => ['sometimes', 'required', 'integer', 'between:1,500'],
            'auto_invite_max_overdue_days' => ['sometimes', 'required', 'integer', 'between:30,730'],
        ];
    }
}
