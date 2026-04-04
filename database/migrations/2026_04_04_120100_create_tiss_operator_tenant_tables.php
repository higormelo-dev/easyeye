<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('tiss_entity_operator_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();

            $table->string('environment', 16)->default('production'); // production|sandbox
            $table->string('endpoint_submission')->nullable();
            $table->string('endpoint_status')->nullable();
            $table->string('endpoint_eligibility')->nullable();
            $table->string('endpoint_authorization')->nullable();

            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->text('token_encrypted')->nullable();

            $table->string('certificate_path')->nullable();
            $table->text('certificate_password_encrypted')->nullable();
            $table->string('private_key_path')->nullable();
            $table->boolean('ssl_enabled')->default(true);

            $table->boolean('active')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['entity_id', 'operator_id', 'environment'],
                'tiss_entity_operator_credentials_entity_operator_env_unique'
            );
            $table->index(
                ['entity_id', 'operator_id', 'active'],
                'tiss_entity_operator_credentials_entity_operator_active_idx'
            );
        });

        Schema::create('tiss_entity_operator_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('operator_id')->constrained('tiss_operators')->cascadeOnDelete();
            $table->foreignUuid('credential_id')->nullable()->constrained('tiss_entity_operator_credentials')->nullOnDelete();
            $table->foreignUuid('preferred_tiss_version_id')->nullable()->constrained('tiss_versions')->nullOnDelete();

            $table->string('contract_code', 64);
            $table->string('plan_code', 64)->nullable();
            $table->string('billing_regime', 32)->default('fee_for_service');
            $table->boolean('requires_authorization')->default(false);
            $table->string('default_guide_type', 24)->default('consultation');
            $table->boolean('active')->default(true);
            $table->json('rules')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['entity_id', 'operator_id', 'contract_code'],
                'tiss_entity_operator_contracts_entity_operator_contract_unique'
            );
            $table->index(
                ['entity_id', 'operator_id', 'active'],
                'tiss_entity_operator_contracts_entity_operator_active_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiss_entity_operator_contracts');
        Schema::dropIfExists('tiss_entity_operator_credentials');
    }
};
