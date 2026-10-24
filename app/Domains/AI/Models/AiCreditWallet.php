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
 * Carteira de saldo de IA em CENTAVOS de BRL — modelo unificado com cota mensal + saldo comprado.
 *
 * Todas as colunas de valor são `*_cents` (inteiros). As colunas antigas (`balance`, `monthly_quota`... em "créditos") ficam
 * congeladas só para auditoria da conversão (ver `legacy_conversion`); o código novo nunca as lê nem escreve.
 *
 * Conceitos:
 *   - balance_cents                : saldo COMPRADO que NUNCA expira (acumula).
 *   - reserved_balance_cents       : porção do saldo comprado reservada em execuções em curso.
 *   - monthly_quota_cents          : franquia mensal do plano (só cobrança automática paga), expira no fim da janela (use-or-lose).
 *   - monthly_quota_used_cents     : quanto da cota foi consumido nesta janela.
 *   - quota_period_ends_at         : fim da janela mensal (ver AiQuotaWindow).
 *
 * Política de consumo: consome a COTA primeiro (que vai expirar), só depois desconta do saldo comprado.
 *
 * Tracking por provedor (analítico): lifetime_consumed_{openai,anthropic,gemini}_cents. NÃO há saldo separado por
 * provedor — o cliente vê 1 saldo total.
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
        'balance_cents',
        'reserved_balance_cents',
        'lifetime_purchased_cents',
        'lifetime_consumed_cents',
        // Cota mensal (expira)
        'monthly_quota_cents',
        'monthly_quota_used_cents',
        'quota_period_ends_at',
        'monthly_quota_lifetime_granted_cents',
        // Tracking analítico por provedor (consumo histórico)
        'lifetime_consumed_openai_cents',
        'lifetime_consumed_anthropic_cents',
        'lifetime_consumed_gemini_cents',
        // Trilha da conversão de créditos antigos para centavos
        'legacy_conversion',
    ];

    protected function casts(): array
    {
        return [
            'balance_cents'                        => 'integer',
            'reserved_balance_cents'               => 'integer',
            'lifetime_purchased_cents'             => 'integer',
            'lifetime_consumed_cents'              => 'integer',
            'monthly_quota_cents'                  => 'integer',
            'monthly_quota_used_cents'             => 'integer',
            'quota_period_ends_at'                 => 'datetime',
            'monthly_quota_lifetime_granted_cents' => 'integer',
            'lifetime_consumed_openai_cents'       => 'integer',
            'lifetime_consumed_anthropic_cents'    => 'integer',
            'lifetime_consumed_gemini_cents'       => 'integer',
            'legacy_conversion'                    => 'array',
            'created_at'                           => 'datetime',
            'updated_at'                           => 'datetime',
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

        return max(0, (int) $this->monthly_quota_cents - (int) $this->monthly_quota_used_cents);
    }

    public function quotaExpired(): bool
    {
        return $this->quota_period_ends_at !== null
            && $this->quota_period_ends_at->isPast();
    }

    /**
     * Último dia (Y-m-d) em que a cota em vigor ainda vale — a janela termina
     * às 00:00 do dia seguinte. Null sem cota em vigor.
     */
    public function quotaLastDay(): ?string
    {
        if ($this->quota_period_ends_at === null || $this->quotaExpired() || (int) $this->monthly_quota_cents <= 0) {
            return null;
        }

        return $this->quota_period_ends_at->copy()->subSecond()->toDateString();
    }

    /**
     * Saldo total disponível para uso (centavos) = cota restante + saldo comprado.
     */
    public function totalAvailable(): int
    {
        return $this->quotaRemaining() + (int) $this->balance_cents;
    }

    // ── Helpers de tracking por provedor ─────────────────────────────────────

    public function lifetimeConsumedFor(AiProvider $provider): int
    {
        return (int) $this->{"lifetime_consumed_{$provider->value}_cents"};
    }

    protected static function newFactory(): AiCreditWalletFactory
    {
        return AiCreditWalletFactory::new();
    }
}
