<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->foreignUuid('entity_integrator_equipment_id')
                ->nullable()
                ->after('schedule_id')
                ->constrained('entity_integrator_equipments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropForeign(['entity_integrator_equipment_id']);
            $table->dropColumn('entity_integrator_equipment_id');
        });
    }
};
