<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('tiss_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('tiss_versions')->nullOnDelete();
            $table->foreignUuid('protocol_id')->nullable()->constrained('tiss_protocols')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->constrained('tiss_xml_documents')->nullOnDelete();

            $table->string('return_type', 40)->default('batch_return');
            $table->string('status', 32)->default('received');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('summary')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status', 'received_at'], 'tiss_returns_entity_status_received_idx');
        });

        Schema::create('tiss_glosas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('guide_id')->nullable()->constrained('tiss_guides')->nullOnDelete();
            $table->foreignUuid('guide_item_id')->nullable()->constrained('tiss_guide_items')->nullOnDelete();
            $table->foreignUuid('return_id')->nullable()->constrained('tiss_returns')->nullOnDelete();
            $table->foreignUuid('protocol_id')->nullable()->constrained('tiss_protocols')->nullOnDelete();

            $table->string('status', 32)->default('open');
            $table->string('glosa_code', 30);
            $table->text('glosa_description')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('identified_at')->nullable();
            $table->date('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status', 'identified_at'], 'tiss_glosas_entity_status_identified_idx');
            $table->index(['entity_id', 'guide_id'], 'tiss_glosas_entity_guide_idx');
        });

        Schema::create('tiss_glosa_appeals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('glosa_id')->constrained('tiss_glosas')->cascadeOnDelete();
            $table->foreignUuid('protocol_id')->nullable()->constrained('tiss_protocols')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->constrained('tiss_xml_documents')->nullOnDelete();

            $table->string('appeal_number', 64);
            $table->string('status', 32)->default('opened');
            $table->text('reason')->nullable();
            $table->decimal('requested_amount', 14, 2)->default(0);
            $table->decimal('accepted_amount', 14, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('result_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'appeal_number'], 'tiss_glosa_appeals_entity_number_unique');
            $table->index(['entity_id', 'status', 'submitted_at'], 'tiss_glosa_appeals_entity_status_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiss_glosa_appeals');
        Schema::dropIfExists('tiss_glosas');
        Schema::dropIfExists('tiss_returns');
    }
};
