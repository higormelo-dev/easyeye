<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O gateway desativou sozinho a recorrência de uma assinatura vigente
 * (ex.: SUBSCRIPTION_INACTIVATED/SUBSCRIPTION_DELETED do Asaas): a cobrança
 * passou para a renovação local e o manager precisa ver o aviso.
 * recurrence_alert_at = quando o aviso foi gerado; volta a nulo quando o
 * manager marca como visto (detalhes no histórico — SubscriptionChange
 * `gateway_recurrence_lost`).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('recurrence_alert_at')->nullable()->after('gateway_subscription_id');
            $table->index('recurrence_alert_at', 'subscriptions_recurrence_alert_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_recurrence_alert_idx');
            $table->dropColumn('recurrence_alert_at');
        });
    }
};
