<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUGFIX (revisao de seguranca): garante em nível de banco que uma assinatura
 * não pode gerar mais de uma comissão para o mesmo período de competência,
 * como defesa em profundidade caso algum código futuro esqueça a checagem
 * de idempotência feita em PartnerService::generateCommission().
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->unique(['subscription_id', 'period'], 'partner_commissions_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->dropUnique('partner_commissions_subscription_period_unique');
        });
    }
};
