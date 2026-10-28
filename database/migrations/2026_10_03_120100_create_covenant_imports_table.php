<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das sincronizações do catálogo global de convênios com a ANS
 * (manager → Convênios → Sincronização ANS): quem disparou (ou a tarefa
 * semanal), de onde vieram os arquivos, quando e o que mudou em operadoras e
 * planos — trilha de uma carga que altera o catálogo de todas as clínicas.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('covenant_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20); // ans | upload | scheduled
            $table->string('status', 20)->default('pending');
            $table->string('phase', 30)->nullable();
            $table->json('modalities');
            $table->string('active_file_path')->nullable();
            $table->string('active_original_name')->nullable();
            $table->string('cancelled_file_path')->nullable();
            $table->string('cancelled_original_name')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->unsignedInteger('skipped_modality')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            // Planos (produtos da ANS) — etapa seguinte às operadoras.
            $table->unsignedInteger('plans_created_count')->default(0);
            $table->unsignedInteger('plans_updated_count')->default(0);
            $table->unsignedInteger('plans_unchanged_count')->default(0);
            $table->unsignedInteger('plans_deactivated_count')->default(0);
            $table->unsignedInteger('plans_skipped_count')->default(0);
            // Falha só da etapa de planos: as operadoras já foram atualizadas.
            $table->text('plans_error')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('covenant_imports');
    }
};
