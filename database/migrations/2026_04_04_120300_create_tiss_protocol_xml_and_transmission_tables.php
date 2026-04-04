<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tiss_xml_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->nullable()->constrained('tiss_operators')->nullOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('tiss_batches')->nullOnDelete();
            $table->foreignUuid('guide_id')->nullable()->constrained('tiss_guides')->nullOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('tiss_versions')->nullOnDelete();

            $table->string('document_type', 40); // batch_submission, protocol_receipt, return_xml...
            $table->string('direction', 16); // outbound|inbound
            $table->string('status', 32)->default('pending');
            $table->string('file_path');
            $table->string('file_hash', 64)->nullable();
            $table->longText('xml_content')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_algorithm', 32)->nullable();
            $table->json('validation_errors')->nullable();
            $table->uuid('generated_by_job_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'direction', 'status'], 'tiss_xml_documents_entity_direction_status_idx');
            $table->index(['entity_id', 'batch_id'], 'tiss_xml_documents_entity_batch_idx');
            $table->index(['entity_id', 'file_hash'], 'tiss_xml_documents_entity_hash_idx');
        });

        Schema::table('tiss_batches', function (Blueprint $table) {
            $table->foreign('xml_document_id', 'tiss_batches_xml_document_fk')
                ->references('id')
                ->on('tiss_xml_documents')
                ->nullOnDelete();
        });

        Schema::create('tiss_protocols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('tiss_batches')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->constrained('tiss_xml_documents')->nullOnDelete();

            $table->string('protocol_number', 80);
            $table->string('status', 32)->default('pending');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'operator_id', 'protocol_number'], 'tiss_protocols_entity_operator_number_unique');
            $table->index(['entity_id', 'status'], 'tiss_protocols_entity_status_idx');
        });

        Schema::create('tiss_transmissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('batch_id')->nullable()->constrained('tiss_batches')->nullOnDelete();
            $table->foreignUuid('xml_document_id')->nullable()->constrained('tiss_xml_documents')->nullOnDelete();
            $table->foreignUuid('protocol_id')->nullable()->constrained('tiss_protocols')->nullOnDelete();

            $table->string('idempotency_key', 120);
            $table->string('status', 32)->default('queued');
            $table->string('queue', 40)->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->longText('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'idempotency_key'], 'tiss_transmissions_entity_idempotency_unique');
            $table->index(['entity_id', 'status', 'requested_at'], 'tiss_transmissions_entity_status_req_idx');
        });

        Schema::create('tiss_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('context_type', 40); // guide|batch|protocol|transmission|xml|glosa|appeal
            $table->uuid('context_id');
            $table->string('previous_status', 32)->nullable();
            $table->string('current_status', 32);
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'context_type', 'context_id'], 'tiss_status_histories_entity_context_idx');
            $table->index(['entity_id', 'changed_at'], 'tiss_status_histories_entity_changed_idx');
        });

        Schema::create('tiss_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('scope', 40); // batch_send, return_process, appeal_submit
            $table->string('key', 120);
            $table->string('fingerprint', 64)->nullable();
            $table->string('status', 32)->default('reserved');
            $table->json('response_snapshot')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'scope', 'key'], 'tiss_idempotency_entity_scope_key_unique');
            $table->index(['entity_id', 'expires_at'], 'tiss_idempotency_entity_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiss_idempotency_keys');
        Schema::dropIfExists('tiss_status_histories');
        Schema::dropIfExists('tiss_transmissions');
        Schema::dropIfExists('tiss_protocols');

        Schema::table('tiss_batches', function (Blueprint $table) {
            $table->dropForeign('tiss_batches_xml_document_fk');
        });

        Schema::dropIfExists('tiss_xml_documents');
    }
};
