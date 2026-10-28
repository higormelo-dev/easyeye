<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cliente do EasyEye no gateway (ex.: cus_… do Asaas) e o que já foi feito
 * nele pelo nosso lado. Hoje: notifications_disabled_at — as notificações do
 * Asaas (e-mail, SMS, WhatsApp, voz, Correios) ficam desligadas para o
 * cliente (notificationDisabled — https://docs.asaas.com/docs/notificacoes);
 * só a régua do EasyEye fala com a clínica. Marcado, não se pede de novo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('billing_gateway_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway_code', 50);
            $table->string('external_customer_id', 191);
            // Só referência (sem FK): a marca vale para o cliente no gateway,
            // mesmo que a empresa tenha sido removida.
            $table->uuid('entity_id')->nullable()->index();
            $table->timestamp('notifications_disabled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway_code', 'external_customer_id'], 'gateway_customers_gateway_external_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_gateway_customers');
    }
};
