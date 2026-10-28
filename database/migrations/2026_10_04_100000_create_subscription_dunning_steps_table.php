<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas da régua de cobrança já cumpridas (comando billing:dunning): uma
 * linha por assinatura, etapa e vencimento. O índice único é a garantia de
 * que rodar o comando de novo (ou duas vezes ao mesmo tempo) não repete o
 * aviso nem o encerramento. Sem dado de paciente.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('subscription_dunning_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('step', 40);
            // Dia do vencimento não pago (ou do vencimento lembrado, no D-5).
            $table->date('due_on');
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'step', 'due_on'], 'subscription_dunning_steps_unique');
            $table->index(['entity_id', 'created_at'], 'subscription_dunning_steps_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_dunning_steps');
    }
};
