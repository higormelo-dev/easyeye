<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tiss_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('tiss_entity_operator_contracts')->nullOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('tiss_versions')->nullOnDelete();
            $table->uuid('xml_document_id')->nullable();

            $table->string('batch_number', 64);
            $table->string('reference_month', 7); // YYYY-MM
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('guides_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'operator_id', 'batch_number'], 'tiss_batches_entity_operator_batch_unique');
            $table->index(['entity_id', 'status', 'reference_month'], 'tiss_batches_entity_status_ref_idx');
        });

        Schema::create('tiss_guides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->nullable()->constrained('tiss_entity_operator_contracts')->nullOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('tiss_versions')->nullOnDelete();

            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('doctors')->nullOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignUuid('medical_record_id')->nullable()->constrained('medical_records')->nullOnDelete();

            $table->string('guide_type', 24)->default('consultation'); // consultation|sadt
            $table->string('guide_number_provider', 64);
            $table->string('guide_number_operator', 64)->nullable();
            $table->string('external_attendance_id', 64)->nullable();
            $table->string('status', 32)->default('draft');

            $table->date('attendance_date');
            $table->date('execution_date')->nullable();
            $table->date('due_date')->nullable();

            $table->string('beneficiary_card_number', 64)->nullable();
            $table->string('beneficiary_name')->nullable();
            $table->string('beneficiary_plan', 64)->nullable();
            $table->string('authorization_number', 64)->nullable();
            $table->text('clinical_indication')->nullable();

            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('denied_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);

            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['entity_id', 'operator_id', 'guide_number_provider'],
                'tiss_guides_entity_operator_provider_number_unique'
            );
            $table->index(['entity_id', 'status', 'attendance_date'], 'tiss_guides_entity_status_attendance_idx');
            $table->index(['entity_id', 'guide_type', 'due_date'], 'tiss_guides_entity_type_due_idx');
        });

        Schema::create('tiss_guide_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('guide_id')->constrained('tiss_guides')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');

            $table->string('tuss_code', 24);
            $table->string('table_code', 8)->default('22');
            $table->string('procedure_code', 32)->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->date('execution_date')->nullable();
            $table->string('authorization_number', 64)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'guide_id', 'status'], 'tiss_guide_items_entity_guide_status_idx');
            $table->index(['entity_id', 'tuss_code'], 'tiss_guide_items_entity_tuss_idx');
        });

        Schema::create('tiss_guide_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('parent_guide_id')->constrained('tiss_guides')->cascadeOnDelete();
            $table->foreignUuid('child_guide_id')->constrained('tiss_guides')->cascadeOnDelete();
            $table->string('link_type', 24)->default('related');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['entity_id', 'parent_guide_id', 'child_guide_id', 'link_type'],
                'tiss_guide_links_entity_parent_child_type_unique'
            );
            $table->index(['entity_id', 'link_type'], 'tiss_guide_links_entity_type_idx');
        });

        Schema::create('tiss_batch_guides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->constrained('tiss_batches')->cascadeOnDelete();
            $table->foreignUuid('guide_id')->constrained('tiss_guides')->cascadeOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['batch_id', 'guide_id'], 'tiss_batch_guides_batch_guide_unique');
            $table->index(['entity_id', 'batch_id'], 'tiss_batch_guides_entity_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiss_batch_guides');
        Schema::dropIfExists('tiss_guide_links');
        Schema::dropIfExists('tiss_guide_items');
        Schema::dropIfExists('tiss_guides');
        Schema::dropIfExists('tiss_batches');
    }
};
