<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Condições contratadas em cada assinatura — antes só existiam no plano:
 *
 * - billing_cycle: ciclo escolhido pelo cliente (o da ativação era descartado).
 * - amount: valor do ciclo no momento da contratação (reajuste do plano não
 *   muda o que o cliente já contratou).
 * - billing_mode: gateway (cobrança automática — a única receita) ou
 *   complimentary (cortesia liberada no manager, com data de término).
 *
 * Linhas antigas: ciclo e valor ficam nulos (leitura cai no plano). O modo é
 * preenchido pelo que já se sabe de cada linha: passou pelo gateway →
 * gateway; trial (em curso ou encerrado) → nulo; o resto foi liberado à mão
 * no manager, sem cobrança → cortesia.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->nullable()->after('plan_id');
            $table->decimal('amount', 10, 2)->nullable()->after('billing_cycle');
            $table->string('billing_mode', 20)->nullable()->after('amount');

            $table->index(['billing_mode', 'status'], 'subscriptions_billing_mode_status_idx');
        });

        DB::table('subscriptions')
            ->where(fn ($q) => $q->whereNotNull('gateway')->orWhereNotNull('billing_state'))
            ->update(['billing_mode' => 'gateway']);

        DB::table('subscriptions')
            ->whereNull('billing_mode')
            ->whereIn('status', ['active', 'past_due'])
            ->update(['billing_mode' => 'complimentary']);

        // Trial que expirou ou foi substituído continua com ends_at nulo; sem
        // este filtro viraria cortesia sem término.
        DB::table('subscriptions')
            ->whereNull('billing_mode')
            ->whereIn('status', ['expired', 'cancelled'])
            ->where(fn ($q) => $q->whereNull('trial_ends_at')->orWhereNotNull('ends_at'))
            ->update(['billing_mode' => 'complimentary']);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_billing_mode_status_idx');
            $table->dropColumn(['billing_cycle', 'amount', 'billing_mode']);
        });
    }
};
