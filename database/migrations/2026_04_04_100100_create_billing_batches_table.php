<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('status', 20)->default('draft');
            $table->string('tiss_version', 16)->default('202603');
            $table->string('tiss_layout_version', 16)->default('04.03.00');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('total_claims')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('xml_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status', 'period_start', 'period_end'], 'billing_batches_entity_status_period_idx');
            $table->unique(['entity_id', 'code'], 'billing_batches_entity_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batches');
    }
};

