<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->string('type', 30)->default('absence')
                ->comment('absence | holiday | meeting | other');
            $table->timestamps();

            $table->index(['doctor_id', 'starts_at', 'ends_at'], 'sb_doctor_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_blocks');
    }
};
