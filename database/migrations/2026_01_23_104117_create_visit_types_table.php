<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visit_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('name');
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
        Schema::table('visit_types', function (Blueprint $table) {
            $table->dropForeign('visit_types_entity_id_foreign');
        });
        Schema::dropIfExists('visit_types');
    }
};
