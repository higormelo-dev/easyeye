<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `provider` em ai_credit_ledger_entries e ai_credit_purchases.
 *
 * Movimentações administrativas globais (estornos por erro, ajustes contábeis)
 * podem ter provider NULL — não estão vinculadas a um provedor específico.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('type')
                ->comment('openai|anthropic|gemini — NULL para ajustes globais.');

            $table->index(['entity_id', 'provider', 'created_at'], 'ai_ledger_entries_entity_provider_idx');
        });

        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('package_code')
                ->comment('openai|anthropic|gemini — qual provedor a compra credita.');

            $table->index(['entity_id', 'provider', 'status'], 'ai_credit_purchases_entity_provider_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('ai_ledger_entries_entity_provider_idx');
            $table->dropColumn('provider');
        });

        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->dropIndex('ai_credit_purchases_entity_provider_idx');
            $table->dropColumn('provider');
        });
    }
};
