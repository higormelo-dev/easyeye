<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M1 — o caso cirúrgico e o que ele pede.
 *
 *  - `surgical_cases`: pedido → autorização → agenda → cirurgia → alta. Entidade
 *    própria (NÃO uma linha de `schedules`: viraria "consulta" no WhatsApp,
 *    repasse e BI). O estado corrente da autorização é desnormalizado aqui a
 *    partir do log imutável `surgical_authorization_events`.
 *  - filhos do pedido: procedimentos TUSS, OPME, LIO planejada, exames de
 *    apoio e equipe.
 *
 * O retrato de segurança clínica (alergias/medicações) é congelado no caso na
 * hora do pedido e reconfirmado na pré-cirurgia.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgical_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('surgeon_doctor_id')->constrained('doctors');
            $table->foreignUuid('covenant_id')->nullable()->constrained('covenants')->nullOnDelete();
            $table->string('covenant_card_number', 40)->nullable();
            $table->foreignUuid('surgery_type_id')->nullable()->constrained('surgery_types')->nullOnDelete();
            $table->string('title', 200);
            $table->string('eye', 2);
            $table->text('technique')->nullable();
            $table->string('anesthesia', 24)->nullable();
            $table->string('regime', 16)->default('ambulatory');
            $table->string('urgency', 10)->default('elective');
            $table->unsignedSmallInteger('estimated_duration_minutes')->nullable();
            $table->foreignUuid('surgery_facility_id')->nullable()->constrained('surgery_facilities')->nullOnDelete();
            $table->foreignUuid('surgery_room_id')->nullable()->constrained('surgery_rooms')->nullOnDelete();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();

            $table->string('status', 30)->default('draft');
            $table->string('status_before_suspension', 30)->nullable();
            $table->jsonb('status_history')->default('[]');

            $table->string('authorization_status', 24)->default('not_requested');
            $table->string('authorization_number', 40)->nullable();
            $table->string('authorization_password', 40)->nullable();
            $table->date('authorization_valid_until')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->timestamp('discharged_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();

            $table->jsonb('cid_codes')->default('[]');
            $table->text('clinical_indication')->nullable();
            $table->unsignedSmallInteger('days_requested')->default(1);
            $table->boolean('needs_opme')->default(false);
            $table->jsonb('clinical_safety_snapshot')->nullable();
            $table->timestamp('clinical_safety_snapshot_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('revision')->default(1);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_id', 'status'], 'surgical_cases_entity_status_idx');
            $table->index(['entity_id', 'patient_id'], 'surgical_cases_entity_patient_idx');
            $table->index(['entity_id', 'surgeon_doctor_id', 'scheduled_start'], 'surgical_cases_surgeon_window_idx');
            $table->index(['entity_id', 'surgery_room_id', 'scheduled_start'], 'surgical_cases_room_window_idx');
            $table->index(['entity_id', 'scheduled_start'], 'surgical_cases_entity_start_idx');
        });

        DB::statement('CREATE UNIQUE INDEX surgical_cases_entity_code_unique ON surgical_cases (entity_id, code)');
        DB::statement('
            ALTER TABLE surgical_cases
              ADD CONSTRAINT surgical_cases_window_check
              CHECK (scheduled_start IS NULL OR scheduled_end IS NULL OR scheduled_end > scheduled_start)
        ');

        Schema::create('surgical_case_procedures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('procedure_id')->nullable()->constrained('procedures')->nullOnDelete();
            $table->string('tuss_code', 10)->nullable();
            $table->string('description', 255);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('access_route', 8)->nullable();
            $table->unsignedSmallInteger('authorized_quantity')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['surgical_case_id', 'position'], 'surgical_case_procedures_case_idx');
        });

        Schema::create('surgical_case_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('entity_product_id')->nullable()->constrained('entity_products')->nullOnDelete();
            $table->string('description', 255);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('manufacturer', 120)->nullable();
            $table->string('anvisa_registration', 15)->nullable();
            $table->string('manufacturer_ref', 60)->nullable();
            $table->string('operating_authorization', 40)->nullable();
            $table->string('manufacturer_option', 2)->nullable();
            $table->decimal('unit_value', 12, 2)->nullable();
            $table->text('technical_justification')->nullable();
            $table->unsignedSmallInteger('authorized_quantity')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['surgical_case_id', 'position'], 'surgical_case_materials_case_idx');
        });

        Schema::create('surgical_case_iol_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->string('eye', 2);
            $table->foreignUuid('entity_product_id')->nullable()->constrained('entity_products')->nullOnDelete();
            $table->string('model_label', 150);
            $table->decimal('power', 5, 2);
            $table->decimal('cylinder', 4, 2)->nullable();
            $table->unsignedSmallInteger('axis')->nullable();
            $table->string('formula', 30)->nullable();
            $table->decimal('a_constant', 5, 2)->nullable();
            $table->decimal('target_refraction', 4, 2)->nullable();
            $table->decimal('predicted_refraction', 4, 2)->nullable();
            $table->foreignUuid('planned_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['surgical_case_id', 'eye'], 'surgical_case_iol_plans_case_eye_unique');
        });

        Schema::create('surgical_case_exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->foreignUuid('patient_exam_id')->constrained('patient_exams')->cascadeOnDelete();
            $table->string('role', 20)->default('other');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['surgical_case_id', 'patient_exam_id'], 'surgical_case_exams_case_exam_unique');
        });

        Schema::create('surgical_team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('surgical_case_id')->constrained('surgical_cases')->cascadeOnDelete();
            $table->string('role', 24);
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('name', 150);
            $table->string('registry', 40)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['surgical_case_id', 'role'], 'surgical_team_members_case_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surgical_team_members');
        Schema::dropIfExists('surgical_case_exams');
        Schema::dropIfExists('surgical_case_iol_plans');
        Schema::dropIfExists('surgical_case_materials');
        Schema::dropIfExists('surgical_case_procedures');
        Schema::dropIfExists('surgical_cases');
    }
};
