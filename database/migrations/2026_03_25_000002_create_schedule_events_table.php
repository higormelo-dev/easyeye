<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('schedule_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors');
            $table->string('title', 150);
            $table->string('type', 30)->default('other'); // meeting, maintenance, personal, training, other
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('color', 7)->nullable();      // hex, e.g. #f97316
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('entity_users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'starts_at']);
            $table->index(['doctor_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_events');
    }
};
