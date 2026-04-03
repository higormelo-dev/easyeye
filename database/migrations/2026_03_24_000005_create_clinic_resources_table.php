<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('clinic_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('room'); // room | equipment
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_resources');
    }
};
