<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('schedule_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')
                ->constrained('schedules')
                ->cascadeOnDelete();
            $table->foreignUuid('resource_id')
                ->constrained('clinic_resources')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['schedule_id', 'resource_id'], 'sr_schedule_resource_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_resources');
    }
};
