<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Reverte o modelo de "saldos separados por provedor" para "saldo único + tracking analítico".
 *
 * Motivação: o pricing do projeto (ai.pricing.usd_per_credit) já é unificado — 1 crédito = $0.01.
 * Saldos separados criavam fricção para o cliente (precisava rebalancear), travavam consensus
 * (que precisa dos 3 provedores), e acoplavam preço ao fornecedor (impedindo trocas de modelo).
 *
 * O que MANTÉM:
 *   - balance, reserved_balance, lifetime_purchased, lifetime_consumed (saldo único e métricas agregadas)
 *   - lifetime_consumed_openai/anthropic/gemini (analítico — para gráficos de consumo por provedor)
 *
 * O que REMOVE:
 *   - balance_openai/anthropic/gemini
 *   - reserved_balance_openai/anthropic/gemini
 *   - lifetime_purchased_openai/anthropic/gemini
 *
 * Antes de dropar, consolida os valores nos campos agregados (não perde dado).
 */
return new class() extends Migration {
    public function up(): void
    {
        // Consolida saldos por provedor → saldo único antes de dropar.
        // Como o saldo agregado já é mantido em sync via syncAggregates(),
        // basta garantir o valor correto.
        DB::table('ai_credit_wallets')->update([
            'balance'            => DB::raw('balance_openai + balance_anthropic + balance_gemini'),
            'reserved_balance'   => DB::raw('reserved_balance_openai + reserved_balance_anthropic + reserved_balance_gemini'),
            'lifetime_purchased' => DB::raw('lifetime_purchased_openai + lifetime_purchased_anthropic + lifetime_purchased_gemini'),
        ]);

        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->dropColumn([
                'balance_openai', 'balance_anthropic', 'balance_gemini',
                'reserved_balance_openai', 'reserved_balance_anthropic', 'reserved_balance_gemini',
                'lifetime_purchased_openai', 'lifetime_purchased_anthropic', 'lifetime_purchased_gemini',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->unsignedInteger('balance_openai')->default(0)->after('balance');
            $table->unsignedInteger('balance_anthropic')->default(0)->after('balance_openai');
            $table->unsignedInteger('balance_gemini')->default(0)->after('balance_anthropic');

            $table->unsignedInteger('reserved_balance_openai')->default(0)->after('reserved_balance');
            $table->unsignedInteger('reserved_balance_anthropic')->default(0)->after('reserved_balance_openai');
            $table->unsignedInteger('reserved_balance_gemini')->default(0)->after('reserved_balance_anthropic');

            $table->unsignedInteger('lifetime_purchased_openai')->default(0)->after('lifetime_purchased');
            $table->unsignedInteger('lifetime_purchased_anthropic')->default(0)->after('lifetime_purchased_openai');
            $table->unsignedInteger('lifetime_purchased_gemini')->default(0)->after('lifetime_purchased_anthropic');
        });

        // Restaura: tudo no provedor primary (openai)
        DB::table('ai_credit_wallets')->update([
            'balance_openai'            => DB::raw('balance'),
            'reserved_balance_openai'   => DB::raw('reserved_balance'),
            'lifetime_purchased_openai' => DB::raw('lifetime_purchased'),
        ]);
    }
};
