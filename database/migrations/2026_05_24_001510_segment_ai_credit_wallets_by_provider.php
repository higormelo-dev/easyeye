<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Segmenta o saldo da carteira de créditos IA por provedor.
 *
 * Antes: 1 saldo único (balance) por entity_id.
 * Depois: 4 métricas (balance, reserved_balance, lifetime_purchased, lifetime_consumed)
 *         para cada um dos 3 provedores (openai, anthropic, gemini) = 12 colunas novas.
 *
 * Estratégia de migração: o saldo legado é movido inteiro para o provedor `openai`
 * (configurado como primary default). Posteriormente, o manager pode redistribuir
 * via ajustes manuais.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            // Saldo disponível por provedor
            $table->unsignedInteger('balance_openai')->default(0)->after('balance');
            $table->unsignedInteger('balance_anthropic')->default(0)->after('balance_openai');
            $table->unsignedInteger('balance_gemini')->default(0)->after('balance_anthropic');

            // Saldo reservado por provedor (em execuções pendentes)
            $table->unsignedInteger('reserved_balance_openai')->default(0)->after('reserved_balance');
            $table->unsignedInteger('reserved_balance_anthropic')->default(0)->after('reserved_balance_openai');
            $table->unsignedInteger('reserved_balance_gemini')->default(0)->after('reserved_balance_anthropic');

            // Lifetime comprado por provedor
            $table->unsignedInteger('lifetime_purchased_openai')->default(0)->after('lifetime_purchased');
            $table->unsignedInteger('lifetime_purchased_anthropic')->default(0)->after('lifetime_purchased_openai');
            $table->unsignedInteger('lifetime_purchased_gemini')->default(0)->after('lifetime_purchased_anthropic');

            // Lifetime consumido por provedor
            $table->unsignedInteger('lifetime_consumed_openai')->default(0)->after('lifetime_consumed');
            $table->unsignedInteger('lifetime_consumed_anthropic')->default(0)->after('lifetime_consumed_openai');
            $table->unsignedInteger('lifetime_consumed_gemini')->default(0)->after('lifetime_consumed_anthropic');
        });

        // Migra saldos existentes para o provedor default (openai)
        DB::table('ai_credit_wallets')->update([
            'balance_openai'            => DB::raw('balance'),
            'reserved_balance_openai'   => DB::raw('reserved_balance'),
            'lifetime_purchased_openai' => DB::raw('lifetime_purchased'),
            'lifetime_consumed_openai'  => DB::raw('lifetime_consumed'),
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->dropColumn([
                'balance_openai', 'balance_anthropic', 'balance_gemini',
                'reserved_balance_openai', 'reserved_balance_anthropic', 'reserved_balance_gemini',
                'lifetime_purchased_openai', 'lifetime_purchased_anthropic', 'lifetime_purchased_gemini',
                'lifetime_consumed_openai', 'lifetime_consumed_anthropic', 'lifetime_consumed_gemini',
            ]);
        });
    }
};
