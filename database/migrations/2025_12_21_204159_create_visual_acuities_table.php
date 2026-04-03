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
        Schema::create('visual_acuities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->integer('scale')->nullable();
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
        Schema::dropIfExists('visual_acuities');
    }
};
