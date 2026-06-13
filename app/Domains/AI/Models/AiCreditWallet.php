<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiProvider;
use App\Models\Entity;
use App\Traits\Auditable;
use Database\Factories\AI\AiCreditWalletFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Carteira de créditos IA — modelo unificado com cota mensal + saldo comprado.
 *
 * Conceitos:
 *   - balance              : créditos COMPRADOS que NUNCA expiram (acumulam).
 *   - reserved_balance     : porção de balance reservada em execuções em curso.
 *   - monthly_quota        : cota dada pelo plano da subscription, expira no fim do ciclo (use-or-lose).
 *   - monthly_quota_used   : quanto da cota foi consumido neste ciclo.
 *   - quota_period_ends_at : quando a cota reseta (geralmente subscription.ends_at).
 *
 * Política de consumo: consome COTA primeiro (que vai expirar), só depois desconta do balance.
 *
 * Tracking por provedor (analítico): lifetime_consumed_openai/anthropic/gemini
 * registra quanto cada provedor consumiu historicamente — usado em dashboards.
 * NÃO há saldo separado por provedor — o cliente vê 1 saldo total.
 */
class AiCreditWallet extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_credit_wallets';

    protected $fillable = [
        'entity_id',
        // Saldo comprado (acumula)
        'balance',
        'reserved_balance',
        'lifetime_purchased',
        'lifetime_consumed',
        // Cota mensal (expira)
        'monthly_quota',
        'monthly_quota_used',
        'quota_period_ends_at',
        'monthly_quota_lifetime_granted',
        // Tracking analítico por provedor (consumo histórico)
        'lifetime_consumed_openai',
        'lifetime_consumed_anthropic',
        'lifetime_consumed_gemini',
    ];

    protected function casts(): array
    {
        return [
            'balance'                        => 'integer',
            'reserved_balance'               => 'integer',
            'lifetime_purchased'             => 'integer',
            'lifetime_consumed'              => 'integer',
            'monthly_quota'                  => 'integer',
            'monthly_quota_used'             => 'integer',
            'quota_period_ends_at'           => 'datetime',
            'monthly_quota_lifetime_granted' => 'integer',
            'lifetime_consumed_openai'       => 'integer',
            'lifetime_consumed_anthropic'    => 'integer',
            'lifetime_consumed_gemini'       => 'integer',
            'created_at'                     => 'datetime',
            'updated_at'                     => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AiCreditLedgerEntry::class, 'wallet_id');
    }

    // ── Helpers de cota ──────────────────────────────────────────────────────

    /**
     * Cota mensal restante (não consumida) deste ciclo.
     * Se a cota já expirou, retorna 0.
     */
    public function quotaRemaining(): int
    {
        if ($this->quotaExpired()) {
            return 0;
        }

        return max(0, $this->monthly_quota - $this->monthly_quota_used);
    }

    public function quotaExpired(): bool
    {
        return $this->quota_period_ends_at !== null
            && $this->quota_period_ends_at->isPast();
    }

    /**
     * Saldo total disponível para uso = cota restante + balance comprado.
     */
    public function totalAvailable(): int
    {
        return $this->quotaRemaining() + (int) $this->balance;
    }

    // ── Helpers de tracking por provedor ─────────────────────────────────────

    public function lifetimeConsumedFor(AiProvider $provider): int
    {
        return (int) $this->{"lifetime_consumed_{$provider->value}"};
    }

    protected static function newFactory(): AiCreditWalletFactory
    {
        return AiCreditWalletFactory::new();
    }
}
