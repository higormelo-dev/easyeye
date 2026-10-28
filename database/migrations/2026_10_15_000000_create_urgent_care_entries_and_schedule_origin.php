<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Pronto atendimento — Fase 1 / B1.
 *
 *  - `urgent_care_entries`: fila de demanda espontânea do dia (paciente sem
 *    hora marcada): motivo oftalmológico, prioridade (imediata, prioritária
 *    por lei — Lei 10.048/2000 —, normal), médico preferido opcional e o
 *    andamento (aguardando → chamado → em atendimento | desistiu | removido).
 *  - `schedules.origin`: o "Atender" da fila cria um agendamento de urgência
 *    no horário atual com origem `urgent_care`. Ele não ocupa horário da grade
 *    (fica fora do índice único de horário do médico, das vagas e do
 *    SlotPicker) e NÃO conta no teto de encaixes (`is_fit_in` = false).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('urgent_care_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->nullable()->constrained('covenants')->nullOnDelete();
            $table->foreignUuid('preferred_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->unsignedSmallInteger('reason');
            $table->string('reason_details', 500)->nullable();
            $table->unsignedSmallInteger('priority');
            $table->unsignedSmallInteger('status')->default(1);
            $table->timestamp('arrived_at');
            $table->timestamp('called_at')->nullable();
            $table->unsignedSmallInteger('call_count')->default(0);
            $table->timestamp('attended_at')->nullable();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('attended_by_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Fila do dia da clínica (status ativo + chegada).
            $table->index(['entity_id', 'status', 'arrived_at'], 'urgent_care_entity_status_arrived_idx');
            $table->index(['entity_id', 'arrived_at'], 'urgent_care_entity_arrived_idx');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('is_fit_in');
        });

        // Atendimento do pronto atendimento não disputa o horário da grade.
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');
        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND situation NOT IN (8, 9)
               AND is_fit_in = false
               AND origin IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn('origin');
        });

        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND situation NOT IN (8, 9)
               AND is_fit_in = false
        ');

        Schema::dropIfExists('urgent_care_entries');
    }
};
