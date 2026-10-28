<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correções da revisão da integração Asaas:
 *
 * billing_refunds:
 *  - gateway_state: o que se sabe do pedido no gateway — queued (ainda não
 *    enviado: o gateway pediu para esperar, 429 — um job envia depois com a
 *    MESMA chave), sent (aceito pelo gateway), inconclusive (timeout/5xx/
 *    conexão: pode ter sido feito — conferido no gateway antes de liberar
 *    outro pedido);
 *  - last_checked_at / check_note: última conferência no gateway (ação
 *    "Conferir" do manager e billing:check-refunds) e o que ela achou (ex.:
 *    boleto aguardando a conta do pagador).
 *
 * invoices.gateway_checked_at: última conferência da cobrança na conciliação
 * diária (billing:reconcile-overdue) — a rodada começa pelas nunca/há mais
 * tempo conferidas e pula as já conferidas hoje, para chegar às recentes.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('billing_refunds', function (Blueprint $table) {
            $table->string('gateway_state', 20)->nullable()->after('status');
            $table->timestamp('last_checked_at')->nullable()->after('completed_at');
            $table->string('check_note', 50)->nullable()->after('last_checked_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('gateway_checked_at')->nullable()->after('paid_at');
            $table->index(['status', 'gateway_checked_at'], 'invoices_status_gateway_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status_gateway_checked_idx');
            $table->dropColumn('gateway_checked_at');
        });

        Schema::table('billing_refunds', function (Blueprint $table) {
            $table->dropColumn(['gateway_state', 'last_checked_at', 'check_note']);
        });
    }
};
