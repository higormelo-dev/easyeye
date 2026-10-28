<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presets de prescrição POR MÉDICO (receituário de medicamentos):
 *  - "minha posologia" salva pelo médico pro medicamento (sobrepõe a genérica
 *    da base como sugestão — nunca entra na receita sem confirmação);
 *  - favoritos e mais usados (usage_count/last_used_at) pras abas
 *    Recentes | Favoritos do modal.
 *
 * Escopo: entity_user (o médico NAQUELA clínica) — preferências não vazam
 * entre clínicas nem entre médicos.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_medication_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('entity_user_id')->constrained('entity_users')->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->text('posology')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_user_id', 'medicine_id']);
            $table->index(['entity_user_id', 'is_favorite']);
            $table->index(['entity_user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_medication_presets');
    }
};
