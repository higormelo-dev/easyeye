<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcação "precisa de conciliação" das assinaturas de cobrança automática
 * criadas pelo código anterior (a 200100 marca as linhas; o comando
 * billing:reconcile-legacy confere no gateway e tira a marcação). Enquanto
 * marcada: fora da régua, da expiração e da renovação local; a vigente da
 * empresa tem acesso total (linha antiga marcada não libera acesso).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('needs_billing_reconciliation')->default(false)->after('billing_mode');

            $table->index('needs_billing_reconciliation', 'subscriptions_needs_reconciliation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_needs_reconciliation_idx');
            $table->dropColumn('needs_billing_reconciliation');
        });
    }
};
