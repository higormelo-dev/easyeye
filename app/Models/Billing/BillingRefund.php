<?php

namespace App\Models\Billing;

use App\Models\{Entity, Subscription, User};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de estorno (total ou parcial) de um pagamento, feito pelo manager.
 * Só vale como devolvido quando o gateway confirma (status done) — até lá
 * "estorno solicitado". Ver App\Services\Billing\RefundService.
 */
class BillingRefund extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'billing_refunds';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Ainda não enviado: o gateway pediu para esperar (429) — SendBillingRefundJob envia com a mesma chave. */
    public const STATE_QUEUED = 'queued';

    /** Aceito pelo gateway (aguardando a confirmação do estorno). */
    public const STATE_SENT = 'sent';

    /** Sem resposta definitiva (timeout/5xx/conexão): conferido no gateway antes de liberar outro pedido. */
    public const STATE_INCONCLUSIVE = 'inconclusive';

    protected $fillable = [
        'entity_id',
        'payment_id',
        'invoice_id',
        'subscription_id',
        'gateway_code',
        'external_payment_id',
        'external_refund_id',
        'amount',
        'partial',
        'status',
        'gateway_state',
        'reason',
        'requested_by',
        'request_url',
        'gateway_response',
        'error_message',
        'requested_at',
        'completed_at',
        'last_checked_at',
        'check_note',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'partial'          => 'boolean',
            'gateway_response' => 'array',
            'requested_at'     => 'datetime',
            'completed_at'     => 'datetime',
            'last_checked_at'  => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
            'deleted_at'       => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
