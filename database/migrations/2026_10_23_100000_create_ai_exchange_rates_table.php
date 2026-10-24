<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Histórico (append-only) da cotação US$→R$ que precifica a IA: o dono do SaaS grava a cotação no manager, com data,
 * autor e observação; corrigir é gravar uma nova linha. A cotação vigente é a última com `effective_at <= agora`
 * (AiExchangeRateService). Plano: docs/internal/planos/ai-credit-packs-brl-plano.md §1.2.
 *
 * Linha inicial: se já houve recarga de provedor com cotação, ela vira uma linha `legacy_topup` (a cotação real paga).
 * Sem recarga, NÃO se inventa linha manual — o manager mostra "defina a cotação" enquanto for o fallback.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 20)->comment('manual|legacy_topup|ptax_bcb');
            $table->decimal('rate_brl_per_usd', 12, 6);
            $table->timestamp('effective_at');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('effective_at', 'ai_exchange_rates_effective_at_idx');
        });

        $topup = DB::table('ai_provider_topups')
            ->whereNotNull('exchange_rate')
            ->orderByDesc('topped_up_at')
            ->first(['exchange_rate', 'topped_up_at']);

        if ($topup !== null) {
            DB::table('ai_exchange_rates')->insert([
                'id'               => (string) Str::uuid(),
                'source'           => 'legacy_topup',
                'rate_brl_per_usd' => $topup->exchange_rate,
                'effective_at'     => $topup->topped_up_at,
                'note'             => 'Última recarga de provedor (migração inicial).',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_exchange_rates');
    }
};
