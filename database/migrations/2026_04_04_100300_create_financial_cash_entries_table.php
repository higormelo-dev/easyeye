<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('financial_cash_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('financial_categories')->nullOnDelete();
            $table->foreignUuid('covenant_id')->nullable()->constrained('covenants')->nullOnDelete();
            $table->foreignUuid('billing_claim_id')->nullable()->constrained('billing_claims')->nullOnDelete();

            $table->string('code', 32);
            $table->date('entry_date');
            $table->string('description');
            $table->string('type', 20); // income | expense
            $table->string('status', 20)->default('paid'); // pending | paid | cancelled
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 40)->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'entry_date', 'type'], 'financial_cash_entity_date_type_idx');
            $table->index(['entity_id', 'status', 'entry_date'], 'financial_cash_entity_status_date_idx');
            $table->index(['entity_id', 'category_id'], 'financial_cash_entity_category_idx');
            $table->unique(['entity_id', 'code'], 'financial_cash_entries_entity_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_cash_entries');
    }
};

