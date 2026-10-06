<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout hospedado do gateway (Asaas Checkout — POST /v3/checkouts,
 * https://docs.asaas.com/reference/criar-novo-checkout): o cartão é digitado
 * na página do Asaas, nunca no EasyEye (PCI). Uma linha por checkout aberto
 * para pagar uma fatura:
 *
 *  - kind recurrent: assinatura no cartão (chargeTypes RECURRENT) — a
 *    recorrência criada pelo checkout só substitui a anterior depois que o
 *    pagamento dela é confirmado (status adopted);
 *  - kind detached: cobrança avulsa no cartão (pacote de créditos de IA,
 *    diferença do upgrade, fatura sem recorrência nova).
 *
 * O Asaas liga a assinatura e as cobranças ao checkout pelo campo
 * checkoutSession (= external_checkout_id); os webhooks CHECKOUT_* mudam o
 * status (active → paid/canceled/expired).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('billing_hosted_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('gateway_code', 50);
            $table->string('external_checkout_id', 191);
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');
            $table->text('url')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('cycle', 20)->nullable();
            $table->date('next_due_date')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('external_subscription_id', 191)->nullable();
            $table->string('external_payment_id', 191)->nullable();
            // Recorrência e cobrança que o pagamento pelo checkout substitui
            // (canceladas no gateway só depois da confirmação).
            $table->string('replaces_recurrence_id', 191)->nullable();
            $table->string('replaces_charge_id', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway_code', 'external_checkout_id'], 'hosted_checkouts_gateway_external_unique');
            $table->index(['invoice_id', 'status'], 'hosted_checkouts_invoice_status_idx');
            $table->index(['gateway_code', 'external_subscription_id'], 'hosted_checkouts_external_subscription_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_hosted_checkouts');
    }
};
