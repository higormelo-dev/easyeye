<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('resource_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('resource_id')
                ->constrained('clinic_resources')
                ->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->string('type')->default('other'); // absence|holiday|meeting|other
            $table->timestamps();

            $table->index(['resource_id', 'starts_at', 'ends_at'], 'rb_resource_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_blocks');
    }
};
