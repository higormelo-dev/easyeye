<?php

declare(strict_types=1);

namespace App\Models\WhatsApp;

use App\Models\{Entity, Schedule};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de mensagens WhatsApp (saída e entrada) — confirmações de consulta,
 * pesquisas de satisfação, respostas do paciente, código de verificação do
 * cadastro e avisos do SaaS (estes dois sem clínica: entity_id nulo).
 *
 * Estados (saída): pending → sending → sent → answered | failed; skipped (acesso da
 * clínica bloqueado — volta para a fila) e suppressed (paciente descadastrado
 * — não volta). Entrega/leitura/falha assíncrona chegam pelo webhook de
 * status (delivered_at, read_at, failed_at + error_code).
 * Entrada: received (uma linha por mensagem recebida, idempotente por
 * provider_message_id — ver índice whatsapp_messages_inbound_once).
 *
 * provider_message_id = id devolvido pela Gupshup no envio (ou o wamid da
 * mensagem recebida); wa_message_id = wamid da Meta da mensagem enviada,
 * chegado no status (usado para casar o context.id da resposta).
 */
class WhatsAppMessage extends Model
{
    use HasUuids;

    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_SURVEY = 'survey';

    public const KIND_ACK = 'ack';

    public const KIND_REPLY = 'reply';

    /** Código de verificação do cadastro (template de autenticação). */
    public const KIND_VERIFICATION = 'verification';

    /** Aviso do SaaS à clínica (régua, fim do teste, cobrança). */
    public const KIND_SAAS_NOTICE = 'saas_notice';

    public const STATUS_PENDING = 'pending';

    /**
     * Gravado ANTES da chamada ao provedor (templates a paciente e avisos do
     * SaaS). Um job que encontra a linha assim não reenvia — a chamada
     * anterior pode ter saído (worker caiu / timeout de leitura): vira
     * failed com error_code unknown_delivery, para revisão.
     */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_FAILED = 'failed';

    /**
     * Envio automático pulado (ex.: acesso da clínica bloqueado —
     * ClinicServiceGate), com o motivo em `error`. Não conta como enviada:
     * o comando volta a enfileirar a mesma linha quando o acesso volta.
     */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_RECEIVED = 'received';

    /** Paciente descadastrado (respondeu SAIR) — não é reenviada. */
    public const STATUS_SUPPRESSED = 'suppressed';

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'entity_id',
        'schedule_id',
        'whatsapp_setting_id',
        'sender_app_id',
        'direction',
        'kind',
        'template',
        'phone',
        'body',
        'status',
        'provider_message_id',
        'wa_message_id',
        'error',
        'error_code',
        'survey_score',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'answered_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'survey_score' => 'integer',
            'sent_at'      => 'datetime',
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
            'failed_at'    => 'datetime',
            'answered_at'  => 'datetime',
            'payload'      => 'array',
        ];
    }

    /** error_code da confirmação substituída porque a consulta foi remarcada. */
    public const ERROR_RESCHEDULED = 'rescheduled';

    /**
     * Consulta remarcada: a confirmação com a data antiga vira `skipped`
     * (error_code rescheduled) — o whatsapp:send-confirmations reaproveita a
     * mesma linha com a nova data (se a consulta seguir Agendada e na janela).
     * Resposta ao aviso antigo não confirma/cancela nada (WhatsAppService
     * confere payload.schedule_at). `sending` fica (envio em andamento — a
     * conferência na resposta cobre); `suppressed` nunca volta.
     */
    public static function supersedeConfirmationsOf(string $scheduleId): int
    {
        return static::query()
            ->where('schedule_id', $scheduleId)
            ->where('direction', 'out')
            ->where('kind', self::KIND_CONFIRMATION)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_ANSWERED])
            ->update([
                'status'     => self::STATUS_SKIPPED,
                'error_code' => self::ERROR_RESCHEDULED,
                'error'      => self::ERROR_RESCHEDULED . ': consulta remarcada — nova confirmação com a nova data pelo comando.',
                'updated_at' => now(),
            ]);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    /** Configuração (app) pela qual a mensagem saiu ou chegou. */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(WhatsAppSetting::class, 'whatsapp_setting_id');
    }
}
