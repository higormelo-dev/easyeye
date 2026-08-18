<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\PlatformExpenseCategory;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

/**
 * Despesa OPERACIONAL do próprio EasyEye (servidor, folha, marketing, imposto,
 * outros) — lançamento manual, exclusivo do painel de Finanças internas
 * (EntityGate::SaasOwnerFinancial). NÃO confundir com o financeiro da CLÍNICA
 * (App\Models\FinancialCashEntry e correlatos, em app/Services/Financial) —
 * aquele é dado do tenant; este é dado da plataforma como um todo.
 *
 * Custo de IA e taxa de gateway NÃO passam por aqui — são derivados
 * automaticamente de `ai_run_provider_calls.raw_cost_usd` e
 * `payments.gateway_fee` respectivamente (ver PlatformFinanceService).
 */
class PlatformExpense extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'category',
        'description',
        'amount',
        'effective_at',
        'recurring',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'category'     => PlatformExpenseCategory::class,
            'amount'       => 'decimal:2',
            'effective_at' => 'date',
            'recurring'    => 'boolean',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }
}
