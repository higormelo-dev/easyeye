<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('resource_work_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')
                ->constrained('clinic_resources')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0=Sun … 6=Sat
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['resource_id', 'day_of_week', 'starts_at'], 'rws_resource_day_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_work_schedules');
    }
};
