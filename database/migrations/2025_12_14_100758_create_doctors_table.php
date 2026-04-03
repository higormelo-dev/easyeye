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
        Schema::create('doctors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code');
            $table->string('record', 50)->nullable();
            $table->string('record_specialty', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('partner')->default(false);
            $table->boolean('active')->default(false);
            $table->longText('observation')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['entity_id', 'code'], 'doctors_entity_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
