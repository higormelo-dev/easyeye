<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('financial_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 20); // income | expense
            $table->boolean('active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'type', 'active'], 'financial_categories_entity_type_active_idx');
            $table->unique(['entity_id', 'code'], 'financial_categories_entity_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_categories');
    }
};

