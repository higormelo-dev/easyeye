<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração da clínica para o módulo cirúrgico (uma linha por clínica).
 * Sem linha = tudo desligado (o aviso por WhatsApp é opt-in da clínica).
 */
class SurgeryClinicSetting extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = ['entity_id', 'authorization_notice_enabled'];

    protected function casts(): array
    {
        return ['authorization_notice_enabled' => 'boolean'];
    }

    /** Aviso de autorização por WhatsApp ligado nesta clínica? Sem linha = desligado. */
    public static function authorizationNoticeEnabled(string $entityId): bool
    {
        return (bool) static::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->value('authorization_notice_enabled');
    }
}
