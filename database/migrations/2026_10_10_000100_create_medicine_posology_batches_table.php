<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de "Gerar posologia com IA" (Manager → Medicamentos): quem disparou,
 * quando, com quais filtros e IA, o que foi feito e quanto custou — histórico
 * na tela e trilha de uma ação que grava no catálogo de todas as clínicas.
 *
 * Cada grupo (itens iguais: princípio ativo + concentração + forma) é UMA
 * chamada de IA aplicada a todos do grupo; o plano fica gravado para o job
 * retomar de onde parou (continuação em fila) e para o histórico de falhas.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medicine_posology_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Entity SaaS da sessão (run de plataforma da IA).
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('phase', 20)->nullable();
            $table->string('provider', 30);
            $table->jsonb('filters')->nullable();
            $table->unsignedInteger('group_limit')->default(0);
            $table->unsignedInteger('total_medicines')->default(0);
            $table->unsignedInteger('total_groups')->default(0);
            $table->unsignedInteger('remaining_groups')->default(0);
            $table->unsignedInteger('processed_groups')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_groups')->default(0);
            $table->unsignedInteger('ai_calls')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('medicine_posology_batch_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('medicine_posology_batches')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('group_key', 1000);
            $table->string('label', 500);
            $table->jsonb('medicine_ids');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_posology_batch_groups');
        Schema::dropIfExists('medicine_posology_batches');
    }
};
