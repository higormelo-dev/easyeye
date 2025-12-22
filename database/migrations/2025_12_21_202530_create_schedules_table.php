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
        Schema::create('schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignUuid('patient_id')
                ->nullable()
                ->constrained('patients')
                ->cascadeOnDelete();
            $table->foreignUuid('covenant_id')
                ->nullable()
                ->constrained('covenants')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('full_name');
            $table->dateTime('date_time');
            $table->string('telephone')->nullable();
            $table->string('cellphone')->nullable();
            $table->boolean('cellphone_whatsapp')->default(false);
            $table->integer('visits')->default(0);
            $table->integer('situation')->default(0);
            $table->boolean('active')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
