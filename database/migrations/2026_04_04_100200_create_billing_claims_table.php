<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('billing_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('billing_batches')->nullOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();

            $table->string('code', 32);
            $table->string('guide_number', 64)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('attendance_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('glosa_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->boolean('is_tiss_exported')->default(false);
            $table->string('tiss_protocol', 64)->nullable();
            $table->string('authorization_code', 64)->nullable();
            $table->string('tuss_code', 32)->nullable();
            $table->string('procedure_description')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status', 'attendance_date'], 'billing_claims_entity_status_date_idx');
            $table->index(['entity_id', 'covenant_id', 'due_date'], 'billing_claims_entity_covenant_due_idx');
            $table->index(['batch_id', 'status'], 'billing_claims_batch_status_idx');
            $table->unique(['entity_id', 'code'], 'billing_claims_entity_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_claims');
    }
};

