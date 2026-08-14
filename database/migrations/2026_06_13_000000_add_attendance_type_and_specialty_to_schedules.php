<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // Classificação da CONSULTA (não do paciente) — muda a cada
            // agendamento. Listas fixas (App\Enums\ScheduleAttendanceType /
            // MedicalSpecialty), por isso tinyInteger + nullable em vez de FK
            // pra um catálogo configurável (ao contrário de covenant_id/visit_id).
            $table->tinyInteger('attendance_type')->nullable()->after('visit_id');
            $table->tinyInteger('specialty_area')->nullable()->after('attendance_type');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['attendance_type', 'specialty_area']);
        });
    }
};
