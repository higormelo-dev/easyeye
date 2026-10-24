<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Saldo, ledger, execuções e compras de IA em CENTAVOS de BRL (plano docs/internal/planos/ai-credit-packs-brl-plano.md §2.1).
 *
 * ADITIVA: as colunas antigas (em "créditos") ficam congeladas, só para auditoria; o código novo lê e escreve apenas
 * `*_cents`. Assim, um leitor esquecido falha de forma visível nos testes em vez de tratar crédito como centavo. A
 * conversão dos saldos antigos é a migration seguinte (AiLegacyCreditConverter, parâmetros em config/ai.php
 * `legacy_conversion`).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            foreach ([
                'balance_cents', 'reserved_balance_cents', 'lifetime_purchased_cents', 'lifetime_consumed_cents',
                'lifetime_consumed_openai_cents', 'lifetime_consumed_anthropic_cents', 'lifetime_consumed_gemini_cents',
                'monthly_quota_cents', 'monthly_quota_used_cents', 'monthly_quota_lifetime_granted_cents',
            ] as $column) {
                $table->bigInteger($column)->default(0);
            }

            $table->jsonb('legacy_conversion')->nullable()->comment('Trilha da conversão de créditos antigos para centavos (fator, método, valores originais).');
        });

        // Nunca negativo: o saldo comprado/reservado/usado da cota é regra de dinheiro, não só de aplicação.
        DB::statement('ALTER TABLE ai_credit_wallets ADD CONSTRAINT ai_credit_wallets_cents_non_negative CHECK (balance_cents >= 0 AND reserved_balance_cents >= 0 AND monthly_quota_cents >= 0 AND monthly_quota_used_cents >= 0)');

        Schema::table('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->bigInteger('amount_cents')->nullable();
            $table->bigInteger('balance_after_cents')->nullable();
            $table->string('unit', 20)->default('legacy_credit')->comment('legacy_credit|brl_cent');
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('estimated_charge_cents')->default(0);
            $table->unsignedBigInteger('reserved_charge_cents')->default(0);
            $table->unsignedBigInteger('consumed_charge_cents')->default(0);
            $table->decimal('estimated_cost_usd', 14, 8)->nullable();
            $table->decimal('actual_cost_usd', 14, 8)->nullable();
            $table->decimal('exchange_rate_brl_usd', 12, 6)->nullable();
            $table->decimal('markup_multiplier', 6, 3)->nullable();
            $table->unsignedInteger('minimum_charge_cents')->nullable();
            $table->string('pricing_source', 20)->nullable()->comment('manual|legacy_topup|fallback|legacy_migration');
            $table->timestamp('priced_at')->nullable();
        });

        Schema::table('ai_run_provider_calls', function (Blueprint $table) {
            $table->unsignedBigInteger('normalized_charge_cents')->nullable()->comment('Diagnóstico por chamada (arredonda para cima); NÃO é o que se debita.');
        });

        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->bigInteger('wallet_credit_cents')->nullable()->comment('Quanto a compra credita na carteira, em centavos (pacote novo: = amount_cents, 1:1).');
        });

        // Compras novas não têm "créditos": a coluna antiga fica só para o histórico.
        DB::statement('ALTER TABLE ai_credit_purchases ALTER COLUMN credits DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ai_credit_wallets DROP CONSTRAINT IF EXISTS ai_credit_wallets_cents_non_negative');

        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->dropColumn('wallet_credit_cents');
        });

        Schema::table('ai_run_provider_calls', function (Blueprint $table) {
            $table->dropColumn('normalized_charge_cents');
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_charge_cents', 'reserved_charge_cents', 'consumed_charge_cents', 'estimated_cost_usd',
                'actual_cost_usd', 'exchange_rate_brl_usd', 'markup_multiplier', 'minimum_charge_cents', 'pricing_source', 'priced_at',
            ]);
        });

        Schema::table('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['amount_cents', 'balance_after_cents', 'unit']);
        });

        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->dropColumn([
                'balance_cents', 'reserved_balance_cents', 'lifetime_purchased_cents', 'lifetime_consumed_cents',
                'lifetime_consumed_openai_cents', 'lifetime_consumed_anthropic_cents', 'lifetime_consumed_gemini_cents',
                'monthly_quota_cents', 'monthly_quota_used_cents', 'monthly_quota_lifetime_granted_cents', 'legacy_conversion',
            ]);
        });
    }
};
