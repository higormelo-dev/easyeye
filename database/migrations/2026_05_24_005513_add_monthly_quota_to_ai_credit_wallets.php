<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa "cota mensal do plano" (use-or-lose) de "créditos comprados" (acumulam).
 *
 * Antes: tudo virava saldo único (cota e compra se misturavam).
 * Depois: ao consumir, sistema usa COTA primeiro (que expira no fim do ciclo),
 *         e só depois desconta do balance comprado (que nunca expira).
 *
 * Isso protege o cliente de desperdiçar comprados quando ainda tem cota disponível.
 *
 * Campos:
 *   - monthly_quota                 : créditos da cota deste ciclo
 *   - monthly_quota_used            : quanto da cota já foi consumido
 *   - quota_period_ends_at          : quando a cota reseta (vinculado à subscription)
 *   - monthly_quota_lifetime_granted: total já concedido como cota (histórico)
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_quota')->default(0)->after('lifetime_consumed');
            $table->unsignedBigInteger('monthly_quota_used')->default(0)->after('monthly_quota');
            $table->timestamp('quota_period_ends_at')->nullable()->after('monthly_quota_used');
            $table->unsignedBigInteger('monthly_quota_lifetime_granted')->default(0)->after('quota_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_wallets', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_quota',
                'monthly_quota_used',
                'quota_period_ends_at',
                'monthly_quota_lifetime_granted',
            ]);
        });
    }
};
