<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->nullOnDelete();
            $table->foreignUuid('medicine_presentation_id')
                ->nullable()
                ->constrained('medicine_presentations')
                ->nullOnDelete();
            $table->string('name');
            $table->string('dosage')->nullable();           // ex: "2 gotas"
            $table->string('frequency')->nullable();        // ex: "3x ao dia"
            $table->string('duration')->nullable();         // ex: "30 dias"
            $table->text('instructions')->nullable();       // orientações de uso
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
