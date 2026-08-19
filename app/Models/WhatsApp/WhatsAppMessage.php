<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\{Entity, Schedule};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de mensagens WhatsApp (saída e entrada) — confirmações de consulta,
 * pesquisas de satisfação e as respostas do paciente.
 *
 * Estados (saída): pending → sent → answered | failed.
 * Entrada: received (uma linha por mensagem recebida, idempotente por
 * zapi_message_id — ver índice whatsapp_messages_inbound_once).
 */
class WhatsAppMessage extends Model
{
    use HasUuids;

    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_SURVEY = 'survey';

    public const KIND_ACK = 'ack';

    public const KIND_REPLY = 'reply';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECEIVED = 'received';

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'entity_id',
        'schedule_id',
        'direction',
        'kind',
        'phone',
        'body',
        'status',
        'zapi_message_id',
        'error',
        'survey_score',
        'sent_at',
        'answered_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'survey_score' => 'integer',
            'sent_at'      => 'datetime',
            'answered_at'  => 'datetime',
            'payload'      => 'array',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }
}
