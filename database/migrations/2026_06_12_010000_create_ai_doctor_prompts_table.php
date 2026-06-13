<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates de prompt favoritos por médico (Onda 3, P1).
 *
 * Cada médico pode salvar até 5 prompts personalizados que aparecem como chips
 * no painel do Assistente de IA. Limite enforced no AiDoctorPromptService::save.
 *
 * entity_id é redundante com doctors.entity_user.entity_id mas evita join em
 * todas as leituras do painel inline e simplifica o cross-tenant guard.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_doctor_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('label', 120);
            $table->text('prompt');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['doctor_id', 'position'], 'ai_doctor_prompts_doctor_position_idx');
            $table->index(['entity_id', 'doctor_id'], 'ai_doctor_prompts_entity_doctor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_doctor_prompts');
    }
};
