<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * O recall do PACIENTE (Fase 6, ADR 0002).
 *
 *  - `patient_conditions`: o quadro crônico de um olho que dispara um protocolo (glaucoma, DMRI...). Nasce
 *    `suggested` (detectado, ainda não confirmado — não gera convite) ou `active`; o médico confirma, descarta
 *    ou dá alta.
 *  - `patient_recalls`: o retorno pendente — DERIVADO (reavaliável a qualquer momento), não é fonte da verdade.
 *    Uma linha por condição+tarefa (ou uma por paciente para o retorno pedido pelo médico) que se reaproveita
 *    a cada ciclo; as datas são persistidas para a lista ser um SELECT indexado.
 *  - `patient_recall_events`: o que aconteceu com cada retorno (append-only).
 *  - `patient_recall_blocks`: quem não deve ser contatado (óbito, pediu para não ser contatado, mudou, sem
 *    telefone válido).
 *  - `recall_dirty_patients`: a OUTBOX transacional — o ato clínico marca o paciente "sujo" na MESMA transação
 *    e o avaliador reavalia só esses (e a conciliação noturna cobre o que o evento não pega).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_conditions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('protocol_key', 60);
            // right | left | both
            $table->string('eye', 5);
            // suggested | active | discharged | rejected
            $table->string('status', 12)->default('suggested');
            // cid | medication | manual
            $table->string('origin', 12);
            // Início do acompanhamento (data da evidência / início do uso do medicamento); nulo = não se sabe.
            $table->date('started_on')->nullable();
            // Só ids, códigos e datas — nunca texto livre do prontuário.
            $table->json('evidence')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discharged_at')->nullable();
            $table->foreignUuid('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('discharge_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_id', 'patient_id']);
            $table->index(['entity_id', 'status', 'protocol_key']);
        });

        DB::statement("CREATE UNIQUE INDEX patient_conditions_one_open ON patient_conditions (entity_id, patient_id, protocol_key, eye) WHERE status IN ('suggested', 'active')");
        DB::statement("ALTER TABLE patient_conditions ADD CONSTRAINT patient_conditions_status_check CHECK (status IN ('suggested', 'active', 'discharged', 'rejected'))");
        DB::statement("ALTER TABLE patient_conditions ADD CONSTRAINT patient_conditions_eye_check CHECK (eye IN ('right', 'left', 'both'))");

        Schema::create('patient_recalls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // protocol | doctor_order
            $table->string('source', 14);
            $table->foreignUuid('patient_condition_id')->nullable()->constrained('patient_conditions')->cascadeOnDelete();
            $table->string('protocol_key', 60)->nullable();
            // A versão do protocolo que gerou a conta (a que a clínica adotou).
            $table->foreignUuid('protocol_version_id')->nullable()->constrained('recall_protocol_versions')->nullOnDelete();
            $table->string('task_key', 60);
            // consultation | exam | doctor_order
            $table->string('kind', 20);
            $table->string('exam_key', 40)->nullable();
            $table->string('eye', 5)->nullable();
            $table->date('last_done_at')->nullable();
            $table->date('anchor_at')->nullable();
            $table->date('due_at')->nullable();
            $table->date('invite_from')->nullable();
            $table->date('overdue_from')->nullable();
            $table->date('lost_from')->nullable();
            // open | dismissed | closed | snoozed | needs_info
            $table->string('lifecycle', 12)->default('open');
            $table->string('closed_reason', 30)->nullable();
            $table->date('snoozed_until')->nullable();
            $table->text('dismissed_reason')->nullable();
            // A versão do protocolo foi suspensa: o retorno fica parado (sem convite) até a retomada.
            $table->boolean('rule_suspended')->default(false);
            $table->timestamp('last_contact_at')->nullable();
            $table->string('last_contact_outcome', 24)->nullable();
            $table->unsignedSmallInteger('contact_count')->default(0);
            // "Feito fora": a clínica registrou que o paciente fez a consulta/o exame em outro serviço nesta data.
            // Entra no cálculo como se o ato tivesse sido registrado aqui (re-ancora o próximo vencimento).
            $table->date('external_done_at')->nullable();
            $table->foreignUuid('external_done_by')->nullable()->constrained('users')->nullOnDelete();
            // Já existe agendamento aberto para este retorno.
            $table->foreignUuid('scheduled_schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->unsignedSmallInteger('evaluator_version')->default(1);
            // Por que as datas são estas (âncora, intervalo usado, limite aplicado) — sem texto livre.
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'patient_id']);
        });

        // Uma linha por condição+tarefa (reaproveitada a cada ciclo) e uma por paciente para o retorno do médico.
        DB::statement("CREATE UNIQUE INDEX patient_recalls_condition_task_unique ON patient_recalls (patient_condition_id, task_key) WHERE source = 'protocol'");
        DB::statement("CREATE UNIQUE INDEX patient_recalls_doctor_order_unique ON patient_recalls (entity_id, patient_id) WHERE source = 'doctor_order'");
        // A lista de retornos e o convite leem POR CLÍNICA e por vencimento, só o que está em aberto.
        DB::statement("CREATE INDEX patient_recalls_list_idx ON patient_recalls (entity_id, due_at) WHERE lifecycle IN ('open', 'snoozed')");
        DB::statement("CREATE INDEX patient_recalls_invite_idx ON patient_recalls (entity_id, invite_from) WHERE lifecycle = 'open' AND rule_suspended = false");
        DB::statement("ALTER TABLE patient_recalls ADD CONSTRAINT patient_recalls_lifecycle_check CHECK (lifecycle IN ('open', 'dismissed', 'closed', 'snoozed', 'needs_info'))");
        DB::statement("ALTER TABLE patient_recalls ADD CONSTRAINT patient_recalls_source_check CHECK (source IN ('protocol', 'doctor_order'))");

        Schema::create('patient_recall_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('recall_id')->nullable()->constrained('patient_recalls')->nullOnDelete();
            $table->string('event', 30);
            $table->uuid('actor_user_id')->nullable();
            // Nome no momento (ou 'system'): o histórico sobrevive à remoção do usuário.
            $table->string('actor_label', 160)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recall_id', 'created_at']);
            $table->index(['entity_id', 'patient_id', 'created_at']);
        });

        Schema::create('patient_recall_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // deceased | do_not_contact | moved | no_valid_phone | other
            $table->string('reason', 20);
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            $table->foreignUuid('released_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['entity_id', 'patient_id']);
        });

        DB::statement('CREATE UNIQUE INDEX patient_recall_blocks_one_active ON patient_recall_blocks (entity_id, patient_id, reason) WHERE released_at IS NULL');

        Schema::create('recall_dirty_patients', function (Blueprint $table) {
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            // O ato que marcou (para diagnóstico): medical_record, exam, schedule, medication, patient, manual...
            $table->string('reason', 30);
            $table->timestamp('marked_at')->useCurrent();

            $table->primary(['entity_id', 'patient_id']);
            $table->index('marked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recall_dirty_patients');
        Schema::dropIfExists('patient_recall_blocks');
        Schema::dropIfExists('patient_recall_events');
        Schema::dropIfExists('patient_recalls');
        Schema::dropIfExists('patient_conditions');
    }
};
