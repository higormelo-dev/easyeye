<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitação/execução ESTRUTURADA de procedimento — App\Models\MedicalRecordProcedure.
 *
 * Fase 3: antes disso, procedimento solicitado só existia como TEXTO LIVRE
 * formatado numa textarea do prontuário (ver App\Services\
 * ProcedureSolicitationService — continua existindo, não foi removido, é
 * complementar). Esta tabela dá ao procedimento um ciclo de vida real
 * (solicitado → executado/cancelado) — é o gatilho confiável que faltava
 * pra ligar consumo de estoque a um evento clínico de verdade, em vez de
 * tentar inferir "foi executado" de uma string narrativa.
 *
 * `patient_id` direto (além de `medical_record_id`), mesmo desenho de
 * medical_record_evolutions: listagem cronológica atravessa TODOS os
 * prontuários do paciente sem join.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_procedures', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')
                ->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('medical_record_id')
                ->constrained('medical_records')->cascadeOnDelete();
            // nullOnDelete (não cascade): se o catálogo de procedimento for
            // removido, o REGISTRO CLÍNICO histórico ("paciente fez X em tal
            // data") tem que sobreviver — só perde o vínculo com o catálogo.
            $table->foreignUuid('procedure_id')->nullable()
                ->constrained('procedures')->nullOnDelete();
            $table->foreignUuid('doctor_id')
                ->constrained('doctors')->cascadeOnDelete();

            // 'right'|'left'|'both'|null — mesmos valores de
            // SurgerySchedulingDocService::EYE_LABELS; null = procedimento
            // não-ocular (ex.: exame sistêmico).
            $table->string('eye')->nullable();
            // Paridade com ProcedureSolicitationService::TYPES (rotina/
            // urgencia/controle/comparativo) — mesmo vocabulário, agora com
            // um campo próprio em vez de só aparecer formatado no texto.
            $table->string('solicitation_type')->nullable();

            $table->string('status')->default('requested'); // App\Enums\MedicalRecordProcedureStatus
            $table->text('notes')->nullable();

            $table->timestamp('executed_at')->nullable();
            $table->foreignUuid('executed_by')->nullable()
                ->constrained('entity_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'created_at']);
            $table->index(['entity_id', 'status']);
            $table->index(['medical_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_procedures');
    }
};
