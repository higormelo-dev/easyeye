<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patient_exams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')
                ->nullable()
                ->constrained('patients')
                ->cascadeOnDelete();
            $table->foreignUuid('doctor_id')
                ->nullable()
                ->constrained('doctors')
                ->cascadeOnDelete();
            $table->foreignUuid('schedule_id')
                ->nullable()
                ->constrained('schedules')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('archive');
            $table->string('name')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_exams');
    }
};
