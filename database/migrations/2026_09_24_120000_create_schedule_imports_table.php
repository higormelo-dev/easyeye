<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Mirror exato de `patient_imports`/`doctor_imports` (ver migrations
     * 2026_05_15_120000_create_patient_imports_table,
     * 2026_05_15_130000_add_preview_to_patient_imports e
     * 2026_09_24_110000_create_doctor_imports_table), para o import em
     * lote de Agendamento (Schedule) via CSV.
     */
    public function up(): void
    {
        Schema::create('schedule_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('file_path');
            $table->string('original_name');
            $table->json('preview')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('errors_file_path')->nullable();
            $table->text('abort_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_imports');
    }
};
