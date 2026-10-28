<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autosave do prontuário — Fase 1 / B2.
 *
 * Rascunho SEPARADO do prontuário oficial: o que o médico digitou e ainda não
 * salvou, gravado a cada ~30 s e ao ocultar a aba. Nunca vira versão/auditoria
 * do prontuário (não toca medical_records), é só do autor (user + clínica),
 * conteúdo criptografado (cast encrypted) e apagado ao salvar/finalizar ou
 * pela limpeza agendada (medical-records:prune-drafts, 7 dias).
 *
 * `context_key` identifica a tela de onde veio: `record:{id}` (edição),
 * `schedule:{id}` (novo atendimento aberto pela Agenda) ou `new` (novo sem
 * agendamento) — um rascunho por autor + paciente + tela.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('medical_record_id')->nullable()->constrained('medical_records')->cascadeOnDelete();
            $table->string('context_key', 60);
            $table->longText('payload');
            $table->timestamps();

            $table->unique(['entity_id', 'user_id', 'patient_id', 'context_key'], 'medical_record_drafts_owner_context_unique');
            $table->index('updated_at', 'medical_record_drafts_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_drafts');
    }
};
