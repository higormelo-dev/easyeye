<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Livro de avisos de autorização por WhatsApp: UMA linha por evento do log de
 * autorização (chave única) — nenhuma mensagem sai duas vezes para o mesmo
 * evento. `processing` fica gravado ANTES do envio: se o processo morrer ali, o
 * evento não é reenviado (aviso perdido é aceitável; duplicado, não).
 */
class SurgeryAuthorizationNotice extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'surgical_authorization_event_id',
        'status',
        'skip_reason',
        'whatsapp_message_id',
    ];

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SurgicalAuthorizationEvent::class, 'surgical_authorization_event_id');
    }
}
