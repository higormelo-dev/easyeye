<?php

namespace App\Models\Billing;

use App\Casts\SanitizedGatewayPayload;
use App\Enums\Billing\{InvoiceStatus, PaymentStatus};
use App\Models\{Entity, Plan, Subscription};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class Invoice extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'subscription_id',
        'plan_id',
        'gateway_id',
        'gateway_code',
        'reference',
        'external_invoice_id',
        'external_subscription_id',
        'payment_url',
        'payment_method',
        'payment_instructions',
        'period_start',
        'period_end',
        'due_at',
        'paid_at',
        'amount',
        'currency',
        'status',
        'billing_reason',
        'metadata',
        'raw_gateway_payload',
        'correlation_id',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'period_start'         => 'date',
            'period_end'           => 'date',
            'due_at'               => 'datetime',
            'paid_at'              => 'datetime',
            'amount'               => 'decimal:2',
            'status'               => InvoiceStatus::class,
            'metadata'             => 'array',
            'raw_gateway_payload'  => SanitizedGatewayPayload::class,
            'payment_instructions' => 'array',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
            'deleted_at'           => 'datetime',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'gateway_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'invoice_id');
    }

    /** Fatura de mudança de plano (diferença proporcional do upgrade — PlanChangeService). */
    public const BILLING_REASON_PLAN_CHANGE = 'plan_change';

    /**
     * Fatura de um pacote de créditos de IA comprado pelo checkout
     * (AiCreditPackCheckoutService): sem assinatura (subscription_id nulo) —
     * fora do ciclo, da régua e da renovação; paga, credita a carteira.
     */
    public const BILLING_REASON_AI_CREDIT_PACK = 'ai_credit_pack';

    /** Fatura de mudança de plano (upgrade pago à parte). */
    public function isPlanChange(): bool
    {
        return $this->billing_reason === self::BILLING_REASON_PLAN_CHANGE;
    }

    /** Fatura de pacote de créditos de IA. */
    public function isAiCreditPack(): bool
    {
        return $this->billing_reason === self::BILLING_REASON_AI_CREDIT_PACK;
    }

    /**
     * Só as faturas de período da assinatura (contratação e renovação): fora
     * a diferença do upgrade, o registro de pagamento não aplicado e o
     * pacote de créditos de IA.
     */
    public function scopeForBillingPeriod($query)
    {
        return $query->where(fn ($q) => $q->whereNull('invoices.billing_reason')
            ->orWhereNotIn('invoices.billing_reason', [self::BILLING_REASON_PLAN_CHANGE, 'unapplied_payment', self::BILLING_REASON_AI_CREDIT_PACK]));
    }

    /**
     * Estorno/chargeback desta cobrança atinge o pagamento que QUITOU a
     * fatura: ela é a metadata.paid_by_charge ou o Payment dela está pago.
     * Esse estorno nunca é tratado como de cobrança substituída.
     */
    public function isSettledByCharge(?string $chargeId, ?Payment $payment = null): bool
    {
        if (blank($chargeId)) {
            return false;
        }

        $paidBy = (string) data_get($this->metadata, 'paid_by_charge', '');

        return ($paidBy !== '' && $paidBy === $chargeId)
            || ($payment !== null && $payment->external_payment_id === $chargeId && $payment->status === PaymentStatus::Paid);
    }

    /**
     * A fatura foi quitada por OUTRA cobrança (paid_by_charge diferente, ou
     * outro Payment dela pago): o estorno/chargeback desta é do pagamento em
     * duplicidade — não volta fatura, assinatura nem créditos.
     */
    public function isSettledByAnotherCharge(?string $chargeId): bool
    {
        if (blank($chargeId)) {
            return false;
        }

        $paidBy = (string) data_get($this->metadata, 'paid_by_charge', '');

        if ($paidBy !== '') {
            return $paidBy !== $chargeId;
        }

        return $this->payments()
            ->where('external_payment_id', '!=', $chargeId)
            ->where('status', PaymentStatus::Paid->value)
            ->exists();
    }

    /**
     * Cobranças desta fatura que ainda valem no gateway: a vigente
     * (external_invoice_id) e as de outra forma mantidas pelo checkout ao
     * alternar Pix/boleto (payment_instructions.*.charge_id), fora as já
     * canceladas (metadata.cancelled_charges). Paga uma, as outras são
     * canceladas (CheckoutService / ProcessWebhookEventService).
     *
     * @return list<string>
     */
    public function liveChargeIds(?string $except = null): array
    {
        $cancelled = (array) data_get($this->metadata, 'cancelled_charges', []);
        $ids       = [(string) $this->external_invoice_id];

        foreach ((array) ($this->payment_instructions ?? []) as $entry) {
            $ids[] = (string) data_get($entry, 'charge_id', '');
        }

        return array_values(array_filter(
            array_unique($ids),
            fn (string $id) => $id !== '' && $id !== $except && ! in_array($id, $cancelled, true),
        ));
    }
}
