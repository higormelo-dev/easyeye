<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('waiting_list', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('doctor_id')->constrained('doctors');
            $table->foreignUuid('patient_id')->nullable()->constrained('patients');
            $table->string('full_name');
            $table->string('telephone', 20)->nullable();
            $table->string('cellphone', 20)->nullable();
            $table->boolean('cellphone_whatsapp')->default(false);
            $table->foreignUuid('covenant_id')->nullable()->constrained('covenants');
            $table->foreignUuid('visit_id')->nullable()->constrained('visit_types');
            $table->text('notes')->nullable();
            $table->date('preferred_date_from')->nullable();
            $table->date('preferred_date_until')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules');
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'doctor_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_list');
    }
};
