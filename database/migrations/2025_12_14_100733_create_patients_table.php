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
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();
            $table->foreignUuid('skin_id')->constrained('skin_types')->cascadeOnDelete();
            $table->foreignUuid('iris_id')->constrained('iris_types')->cascadeOnDelete();
            $table->string('code');
            $table->string('card_number')->nullable();
            $table->boolean('active')->default(false);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['entity_id', 'code'], 'patients_entity_id_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
