<?php

namespace App\Models\Billing;

use App\Models\{Entity, Subscription};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkout hospedado do gateway aberto para pagar uma fatura no cartão
 * (Asaas Checkout — https://docs.asaas.com/docs/checkout-asaas). Ver a
 * migration billing_hosted_checkouts e App\Services\Billing\HostedCheckoutService.
 */
class HostedCheckout extends Model
{
    use Auditable;
    use HasUuids;

    protected $table = 'billing_hosted_checkouts';

    /** Assinatura no cartão: a recorrência criada pelo checkout substitui a anterior. */
    public const KIND_RECURRENT = 'recurrent';

    /** Cobrança avulsa no cartão (pacote de IA, upgrade, fatura sem recorrência nova). */
    public const KIND_DETACHED = 'detached';

    /** Aberto: o pagador ainda pode concluir. */
    public const STATUS_ACTIVE = 'active';

    /** CHECKOUT_PAID (a confirmação financeira é a da cobrança, PAYMENT_CONFIRMED/RECEIVED). */
    public const STATUS_PAID = 'paid';

    /** Pagamento confirmado e aplicado à fatura (recorrente: a recorrência nova já é a da assinatura). */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    /** A cobrança do checkout foi recusada: a recorrência nova é desfeita, a antiga segue. */
    public const STATUS_FAILED = 'failed';

    /** A fatura foi paga por outra cobrança antes: a recorrência nova é desfeita. */
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'invoice_id',
        'gateway_code',
        'external_checkout_id',
        'kind',
        'status',
        'url',
        'amount',
        'cycle',
        'next_due_date',
        'expires_at',
        'external_subscription_id',
        'external_payment_id',
        'replaces_recurrence_id',
        'replaces_charge_id',
        'metadata',
        'correlation_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'next_due_date' => 'date',
            'expires_at'    => 'datetime',
            'completed_at'  => 'datetime',
            'metadata'      => 'array',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function isRecurrent(): bool
    {
        return $this->kind === self::KIND_RECURRENT;
    }

    /** O pagador ainda pode usar o link (aberto e dentro da validade). */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && filled($this->url)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Já terminou (pago e aplicado, desfeito, cancelado ou expirado). */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELED, self::STATUS_EXPIRED, self::STATUS_FAILED, self::STATUS_SUPERSEDED], true);
    }
}
