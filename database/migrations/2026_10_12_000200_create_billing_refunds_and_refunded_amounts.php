<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estorno pelo manager (Assinaturas → detalhe → faturas → Estornar) e
 * estornos parciais vindos do gateway.
 *
 * billing_refunds: um pedido de estorno (total ou parcial) com a
 * justificativa e quem pediu. Fica "requested" até o gateway confirmar
 * (Asaas: refunds[].status DONE / PAYMENT_REFUNDED / PAYMENT_PARTIALLY_REFUNDED
 * — https://docs.asaas.com/docs/estornos); boleto devolve request_url (o
 * pagador informa os dados bancários — POST /v3/payments/{id}/bankSlip/refund).
 *
 * payments/invoices.refunded_amount: quanto já foi devolvido (soma dos
 * estornos confirmados). Estorno parcial não muda o status do pagamento nem
 * o acesso da assinatura — só registra.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('billing_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('gateway_code', 50);
            $table->string('external_payment_id', 191);
            $table->string('external_refund_id', 191)->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('partial')->default(false);
            $table->string('status', 20)->default('requested');
            $table->text('reason');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('request_url')->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payment_id', 'status'], 'billing_refunds_payment_status_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('net_amount');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });

        Schema::dropIfExists('billing_refunds');
    }
};
