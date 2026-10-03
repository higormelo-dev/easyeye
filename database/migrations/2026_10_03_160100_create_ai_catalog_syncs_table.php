<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das sincronizações do catálogo de modelos/preços de IA
 * (Manager → Provedores de IA): botão "Sincronizar agora" ou verificação
 * diária (ai:sync-model-catalog). Progresso por WebSocket, como as demais
 * cargas (ImportProgressUpdated).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_catalog_syncs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('manual'); // manual | scheduled
            $table->string('status', 20)->default('pending')->index();
            $table->string('phase', 20)->nullable();
            $table->unsignedSmallInteger('total_providers')->default(0);
            $table->unsignedSmallInteger('processed_providers')->default(0);
            // Versão do catálogo de preços baixado (ETag) — trilha do que foi aplicado.
            $table->string('prices_version', 120)->nullable();
            // Resultado por provedor: {openai: {status, listed, message}}.
            $table->jsonb('providers')->nullable();
            // Amostras do que mudou (preços alterados, modelos novos, sem preço...).
            $table->jsonb('details')->nullable();
            $table->unsignedInteger('models_listed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('locked_count')->default(0);
            $table->unsignedInteger('unlisted_count')->default(0);
            $table->unsignedInteger('missing_price_count')->default(0);
            $table->unsignedInteger('suspicious_count')->default(0);
            $table->text('notice')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_catalog_syncs');
    }
};
