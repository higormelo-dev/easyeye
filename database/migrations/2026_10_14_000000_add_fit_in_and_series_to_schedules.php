<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Agenda — Fase 1.
 *
 *  - Encaixe (`is_fit_in`): paciente a mais, por cima da grade. Pode ocupar um
 *    horário já tomado ou fora do intervalo do médico, então NÃO entra no
 *    índice único de horário do médico (que continua barrando dois
 *    agendamentos normais no mesmo horário). O teto de encaixes é por médico +
 *    dia + turno (`doctors.fit_in_limit_*`, padrão 2; 0 = não aceita) e é
 *    conferido sob lock em ScheduleService::assertFitInCapacity().
 *  - Série de sessões (`series_id`, `session_number`, `session_total`): os
 *    agendamentos de um tratamento seriado (injeção intravítrea, campimetria,
 *    ortóptica…) — sem pacote financeiro.
 *  - "Poucas vagas" do calendário da Agenda: percentual por clínica (padrão 20%).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->boolean('is_fit_in')->default(false)->after('situation');
            $table->uuid('series_id')->nullable()->after('recurrence_until');
            $table->unsignedSmallInteger('session_number')->nullable()->after('series_id');
            $table->unsignedSmallInteger('session_total')->nullable()->after('session_number');
            $table->index('series_id', 'schedules_series_id_idx');
        });

        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');

        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND situation NOT IN (8, 9)
               AND is_fit_in = false
        ');

        // Contagem do teto de encaixes (médico + intervalo do turno).
        DB::statement('
            CREATE INDEX schedules_doctor_fit_in_idx
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND is_fit_in = true
        ');

        Schema::table('doctors', function (Blueprint $table) {
            $table->unsignedSmallInteger('fit_in_limit_morning')->default(2)->after('schedule_interval');
            $table->unsignedSmallInteger('fit_in_limit_afternoon')->default(2)->after('fit_in_limit_morning');
            $table->unsignedSmallInteger('fit_in_limit_evening')->default(2)->after('fit_in_limit_afternoon');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->unsignedTinyInteger('few_slots_percent')->default(20)->after('schedule_interval');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('few_slots_percent');
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn(['fit_in_limit_morning', 'fit_in_limit_afternoon', 'fit_in_limit_evening']);
        });

        DB::statement('DROP INDEX IF EXISTS schedules_doctor_fit_in_idx');
        DB::statement('DROP INDEX IF EXISTS schedules_doctor_datetime_active_unique');

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('schedules_series_id_idx');
            $table->dropColumn(['is_fit_in', 'series_id', 'session_number', 'session_total']);
        });

        DB::statement('
            CREATE UNIQUE INDEX schedules_doctor_datetime_active_unique
                ON schedules (doctor_id, date_time)
             WHERE deleted_at IS NULL
               AND situation NOT IN (8, 9)
        ');
    }
};
