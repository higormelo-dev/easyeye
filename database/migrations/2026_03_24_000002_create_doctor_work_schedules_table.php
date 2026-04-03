<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('doctor_work_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')
                ->comment('0=Domingo, 1=Segunda, 2=Terça, 3=Quarta, 4=Quinta, 5=Sexta, 6=Sábado');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['doctor_id', 'day_of_week', 'starts_at'], 'dws_doctor_day_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_work_schedules');
    }
};
