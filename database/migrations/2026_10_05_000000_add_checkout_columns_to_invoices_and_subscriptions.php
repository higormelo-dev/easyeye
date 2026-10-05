<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout transparente (a clínica paga a assinatura sem sair do EasyEye).
 *
 * invoices:
 *  - payment_method: forma da cobrança vigente da fatura (pix, boleto,
 *    credit_card); nulo = a cobrança deixa o pagador escolher (fatura do
 *    Asaas "Pergunte ao cliente") ou foi emitida antes do checkout;
 *  - payment_instructions: Pix (copia-e-cola, QR, expiração) e boleto (linha
 *    digitável, PDF, vencimento) da cobrança vigente, para a tela não
 *    consultar o gateway a cada abertura. Só dados de pagamento da cobrança
 *    — nada de cartão nem de paciente.
 *
 * subscriptions — cartão salvo para a renovação automática:
 *  - payment_method: forma escolhida para os próximos ciclos (credit_card =
 *    a renovação cobra o cartão salvo sem interação);
 *  - gateway_card_id: id/token do cartão guardado NO GATEWAY (card_…, pm_…,
 *    CARD_…) — o número, o CVV e a validade nunca passam pelo servidor;
 *  - card_brand / card_last4: bandeira e 4 últimos dígitos, só para a tela
 *    ("Visa final 4242"; o PCI DSS permite exibir/guardar os 4 últimos);
 *  - card_installments: parcelas escolhidas na contratação (renovação anual
 *    repete, até o limite do checkout).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_url');
            $table->json('payment_instructions')->nullable()->after('payment_method');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('gateway_subscription_id');
            $table->string('gateway_card_id', 191)->nullable()->after('payment_method');
            $table->string('card_brand', 30)->nullable()->after('gateway_card_id');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_installments')->nullable()->after('card_last4');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'gateway_card_id', 'card_brand', 'card_last4', 'card_installments']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_instructions']);
        });
    }
};
