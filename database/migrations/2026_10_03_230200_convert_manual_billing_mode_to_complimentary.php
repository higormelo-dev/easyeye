<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A modalidade "paga por fora" (manual) saiu: sem cobrança automática pelo
 * gateway, a assinatura é cortesia. Converte o que a primeira versão da
 * migração de condições já tinha gravado como manual:
 *
 * - manual → cortesia, sem valor (não é receita);
 * - cortesia não tem ciclo de cobrança.
 *
 * Sem volta: depois da conversão não dá para saber quais eram "manual".
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('billing_mode', 'manual')
            ->update(['billing_mode' => 'complimentary', 'amount' => null]);

        DB::table('subscriptions')
            ->where('billing_mode', 'complimentary')
            ->update(['billing_cycle' => null]);
    }

    public function down(): void
    {
        // Irreversível de propósito (ver docblock).
    }
};
