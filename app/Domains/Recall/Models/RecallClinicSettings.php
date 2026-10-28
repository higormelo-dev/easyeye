<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A configuração geral do recall DA CLÍNICA (uma linha por clínica): o retorno pedido pelo médico, o convite
 * por WhatsApp (nasce DESLIGADO) e as travas de envio (janela, intervalo mínimo, teto diário com ramp-up).
 *
 * @property string $entity_id
 * @property bool   $doctor_order_enabled
 * @property bool   $whatsapp_invites_enabled
 */
class RecallClinicSettings extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'recall_clinic_settings';

    /** Dias úteis (ISO: 1 = segunda … 7 = domingo) em que o convite pode sair. */
    public const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5];

    protected $fillable = [
        'entity_id', 'doctor_order_enabled', 'whatsapp_invites_enabled', 'invite_window_start', 'invite_window_end',
        'invite_weekdays', 'min_days_between_invites', 'max_auto_invites_per_cycle', 'daily_invite_cap',
        'ramp_started_on', 'auto_invite_max_overdue_days',
    ];

    protected function casts(): array
    {
        return [
            'doctor_order_enabled'         => 'boolean',
            'whatsapp_invites_enabled'     => 'boolean',
            'invite_weekdays'              => 'array',
            'min_days_between_invites'     => 'integer',
            'max_auto_invites_per_cycle'   => 'integer',
            'daily_invite_cap'             => 'integer',
            'auto_invite_max_overdue_days' => 'integer',
            'ramp_started_on'              => 'date',
        ];
    }

    /**
     * A configuração da clínica; sem linha ainda, devolve os padrões (NÃO grava — a tela cria na 1ª edição).
     * Sempre por `entity_id` explícito: o escopo de tenant é inerte em job e CLI.
     */
    public static function forEntity(string $entityId): self
    {
        return static::query()->withoutGlobalScopes()->where('entity_id', $entityId)->first() ?? new self([
            'entity_id'                    => $entityId,
            'doctor_order_enabled'         => true,
            'whatsapp_invites_enabled'     => false,
            'invite_window_start'          => '09:00',
            'invite_window_end'            => '18:00',
            'invite_weekdays'              => self::DEFAULT_WEEKDAYS,
            'min_days_between_invites'     => 30,
            'max_auto_invites_per_cycle'   => 3,
            'daily_invite_cap'             => 20,
            'auto_invite_max_overdue_days' => 180,
        ]);
    }
}
