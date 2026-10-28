<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5 vale uma vez: contratação aguardando o 1º pagamento só dá acesso até
 * first_charge_grace_ends_at. Nulo = regra anterior (fim do dia do vencimento
 * da 1ª cobrança — linhas já existentes). A ativação grava:
 *  - 1ª contratação (sem outra não paga nos últimos 30 dias): o fim do dia
 *    do vencimento da 1ª cobrança;
 *  - nova contratação depois de uma não paga encerrada/pendente: no máximo o
 *    acesso que a anterior ainda dava (nunca estende) — sem ele, nenhum até
 *    o pagamento.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('first_charge_grace_ends_at')->nullable()->after('next_billing_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('first_charge_grace_ends_at');
        });
    }
};
