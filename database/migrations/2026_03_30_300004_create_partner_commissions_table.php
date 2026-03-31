<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comissões geradas para parceiros por conversão de clínicas.
 * Calculadas automaticamente quando uma clínica atribuída ao parceiro
 * ativa um plano pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();

            // Valor da comissão calculado (R$)
            $table->decimal('amount', 10, 2);

            // Taxa aplicada no cálculo (snapshot da taxa do parceiro no momento)
            $table->decimal('rate', 5, 2);

            // Período de competência (YYYY-MM)
            $table->string('period', 7);

            $table->string('status'); // CommissionStatus enum

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index(['partner_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commissions');
    }
};
