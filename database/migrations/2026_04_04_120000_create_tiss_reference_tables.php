<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tiss_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code', 16)->unique(); // ex: 202603
            $table->string('layout_version', 16); // ex: 04.03.00
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'effective_from'], 'tiss_versions_active_effective_idx');
        });

        Schema::create('tiss_operators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('ans_code', 12)->unique();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('webservice_base_url')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'name'], 'tiss_operators_active_name_idx');
        });

        Schema::create('tiss_tuss_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code', 24);
            $table->string('table_code', 8)->default('22');
            $table->string('description');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'table_code'], 'tiss_tuss_code_table_unique');
            $table->index(['active', 'code'], 'tiss_tuss_active_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiss_tuss_codes');
        Schema::dropIfExists('tiss_operators');
        Schema::dropIfExists('tiss_versions');
    }
};
