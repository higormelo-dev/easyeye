<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Último health check real do gateway (billing:gateway-health, diário): uma
 * chamada leve e autenticada à API — no Asaas GET /v3/myAccount/status
 * (https://docs.asaas.com/reference/consultar-situacao-cadastral-da-conta),
 * que também mantém a chave em uso (sem uso por 3 meses ela é desabilitada —
 * https://docs.asaas.com/docs/chaves-de-api). Manager → Gateways mostra.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->json('health')->nullable()->after('config');
            $table->timestamp('health_checked_at')->nullable()->after('health');
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropColumn(['health', 'health_checked_at']);
        });
    }
};
