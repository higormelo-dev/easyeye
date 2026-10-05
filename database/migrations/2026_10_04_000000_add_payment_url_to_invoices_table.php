<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link de pagamento da cobrança (página da fatura, boleto ou Pix) que o
 * gateway devolve — ex.: invoiceUrl/bankSlipUrl do Asaas. Fica na fatura para
 * o cliente receber de novo o link de uma cobrança vencida (régua) e para o
 * manager consultar.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_url', 2048)->nullable()->after('external_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_url');
        });
    }
};
