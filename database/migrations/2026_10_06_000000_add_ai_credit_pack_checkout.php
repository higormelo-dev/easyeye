<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compra de créditos de IA pelo checkout (AiCreditPackCheckoutService):
 *
 *  - a fatura do pacote (billing_reason = ai_credit_pack) não pertence a
 *    nenhuma assinatura — fica fora do ciclo, da régua e da renovação; por
 *    isso subscription_id passa a aceitar nulo em invoices, payments e
 *    payment_attempts (as linhas da assinatura seguem sempre preenchidas);
 *  - o pedido (ai_credit_purchases) guarda a fatura que o paga.
 */
return new class() extends Migration {
    public function up(): void
    {
        foreach (['invoices', 'payments', 'payment_attempts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('subscription_id')->nullable()->change();
            });
        }

        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->foreignUuid('invoice_id')->nullable()->after('subscription_id')
                ->constrained('invoices')->nullOnDelete();
            $table->index('invoice_id', 'ai_credit_purchases_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_purchases', function (Blueprint $table) {
            $table->dropIndex('ai_credit_purchases_invoice_idx');
            $table->dropConstrainedForeignId('invoice_id');
        });

        // Volta a exigir a assinatura só se não houver fatura de pacote.
        foreach (['payment_attempts', 'payments', 'invoices'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('subscription_id')->nullable(false)->change();
            });
        }
    }
};
