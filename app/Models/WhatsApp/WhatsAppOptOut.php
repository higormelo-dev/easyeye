<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Descadastro do WhatsApp por número que envia: o paciente respondeu
 * SAIR/PARAR/STOP (source keyword) ou o provedor recusou por número
 * descadastrado (source provider). Respeitado em todo envio pelo app
 * daquela configuração; VOLTAR/ATIVAR apaga a linha (reativa). A trilha
 * fica nas mensagens recebidas/enviadas (whatsapp_messages).
 *
 * phone no formato de WhatsAppService::normalizePhone (com o 9º dígito).
 */
class WhatsAppOptOut extends Model
{
    use HasUuids;

    public const SOURCE_KEYWORD = 'keyword';

    public const SOURCE_PROVIDER = 'provider';

    protected $table = 'whatsapp_opt_outs';

    protected $fillable = ['whatsapp_setting_id', 'phone', 'source', 'opted_out_at'];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(WhatsAppSetting::class, 'whatsapp_setting_id');
    }

    public static function isSuppressed(WhatsAppSetting $sender, string $phone): bool
    {
        return self::query()
            ->where('whatsapp_setting_id', $sender->id)
            ->where('phone', $phone)
            ->exists();
    }
}
