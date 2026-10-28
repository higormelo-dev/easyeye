<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de fim do teste grátis já enviados (comando billing:trial-notices):
 * uma linha por assinatura, passo (3 dias antes, 1 dia antes, no dia) e data
 * de fim do trial. O índice único garante que rodar o comando de novo não
 * repete o aviso; trial estendido tem outra data de fim, logo avisos novos.
 * Sem dado de paciente.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('subscription_trial_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('step', 20);
            $table->date('trial_ends_on');
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->unsignedSmallInteger('whatsapp_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'step', 'trial_ends_on'], 'subscription_trial_notices_unique');
            $table->index(['entity_id', 'created_at'], 'subscription_trial_notices_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_trial_notices');
    }
};
