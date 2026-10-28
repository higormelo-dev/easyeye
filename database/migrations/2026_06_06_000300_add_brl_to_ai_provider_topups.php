<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recargas dos provedores são pagas em R$ (cartão/fatura, com IOF/spread), mas o
 * provedor credita em US$ — e o consumo das APIs é em US$. Guardamos o valor pago
 * em R$ (amount_brl) e a cotação efetiva (exchange_rate) ao lado do amount_usd,
 * que continua sendo a base do saldo estimado (recarga_USD − consumo_USD).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_provider_topups', function (Blueprint $table) {
            $table->decimal('amount_brl', 14, 2)->nullable()->after('amount_usd');
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('amount_brl'); // R$ por US$
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_topups', function (Blueprint $table) {
            $table->dropColumn(['amount_brl', 'exchange_rate']);
        });
    }
};
