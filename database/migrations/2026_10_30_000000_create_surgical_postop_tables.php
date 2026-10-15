<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M5 — pós-operatório.
 *
 *  - `schedules.surgical_case_id`: o retorno da Agenda aponta para o caso (os
 *    retornos continuam consultas de verdade: repasse, WhatsApp, BI).
 *  - `surgical_postop_returns`: o PLANO de retornos do caso (D1/D7/D30 e o
 *    retorno urgente em 24 h). É a chave de idempotência: uma linha por caso +
 *    marco, que sobrevive mesmo se a recepção cancelar o agendamento — o evento
 *    "cirurgia realizada" reentregue nunca recria o que já existiu. Guarda
 *    também a falha (sem horário livre, erro) para o alerta da aba.
 *  - `surgical_postop_evaluations`: avaliação estruturada por marco e olho
 *    (AV, PIO, achados, refração residual). Rascunho → finalizada (imutável,
 *    com hash); correção só por adendo (`..._addenda`).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignUuid('surgical_case_id')->nullable()->after('series_id')
                ->constrained('surgical_cases')->nullOnDelete();
            $table->index(['entity_id', 'surgical_case_id'], 'schedules_entity_surgical_case_idx');
        });

        Schema::create('surgical_postop_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors');
            $table->string('milestone', 8);
            $table->string('eye', 2);
            $table->timestamp('evaluated_at');

            $table->foreignUuid('va_uncorrected_id')->nullable()->constrained('visual_acuity_types')->nullOnDelete();
            $table->foreignUuid('va_corrected_id')->nullable()->constrained('visual_acuity_types')->nullOnDelete();
            $table->decimal('iop_mmhg', 4, 1)->nullable();
            $table->jsonb('findings')->default('[]');
            $table->text('findings_notes')->nullable();
            $table->decimal('refraction_sphere', 4, 2)->nullable();
            $table->decimal('refraction_cylinder', 4, 2)->nullable();
            $table->unsignedSmallInteger('refraction_axis')->nullable();
            $table->decimal('spherical_equivalent', 4, 2)->nullable();
            $table->text('complications')->nullable();
            $table->string('adherence', 10)->nullable();
            $table->text('plan')->nullable();
            $table->boolean('severe')->default(false);

            $table->string('status', 10)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUuid('finalized_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('revision')->default(1);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['surgical_case_id', 'milestone'], 'surgical_postop_eval_case_idx');
            $table->index(['entity_id', 'status', 'finalized_at'], 'surgical_postop_eval_entity_idx');
        });

        // Um por marco e olho (D1/D7/D30); "outro" pode repetir.
        DB::statement("
            CREATE UNIQUE INDEX surgical_postop_eval_milestone_unique
                ON surgical_postop_evaluations (surgical_case_id, milestone, eye)
             WHERE milestone <> 'other'
        ");

        Schema::create('surgical_postop_evaluation_addenda', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_postop_evaluation_id')->constrained('surgical_postop_evaluations')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('doctor_id')->constrained('doctors');
            $table->text('text');
            $table->decimal('corrected_sphere', 4, 2)->nullable();
            $table->decimal('corrected_cylinder', 4, 2)->nullable();
            $table->unsignedSmallInteger('corrected_axis')->nullable();
            $table->decimal('corrected_spherical_equivalent', 4, 2)->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index('surgical_postop_evaluation_id', 'surgical_postop_addenda_eval_idx');
        });

        Schema::create('surgical_postop_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->string('milestone', 8);
            $table->unsignedSmallInteger('offset_days')->nullable();
            $table->foreignUuid('source_evaluation_id')->nullable()->constrained('surgical_postop_evaluations')->nullOnDelete();
            $table->date('target_date');
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->string('failure_code', 40)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            // Token da reivindicação vigente: só quem o tem grava o resultado (worker com lease vencido não sobrescreve).
            $table->string('claim_token', 36)->nullable();
            $table->timestamps();

            $table->index(['surgical_case_id', 'status'], 'surgical_postop_returns_case_idx');
        });

        // Chave de idempotência: um retorno planejado por caso e marco (D1/D7/D30)
        // e um urgente por avaliação que o pediu.
        DB::statement("
            CREATE UNIQUE INDEX surgical_postop_returns_milestone_unique
                ON surgical_postop_returns (surgical_case_id, milestone)
             WHERE milestone <> 'urgent'
        ");
        DB::statement('
            CREATE UNIQUE INDEX surgical_postop_returns_urgent_unique
                ON surgical_postop_returns (source_evaluation_id)
             WHERE source_evaluation_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_postop_returns');
        Schema::dropIfExists('surgical_postop_evaluation_addenda');
        Schema::dropIfExists('surgical_postop_evaluations');

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_entity_surgical_case_idx');
            $table->dropConstrainedForeignId('surgical_case_id');
        });
    }
};
