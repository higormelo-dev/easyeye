<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_credit_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0)->comment('Créditos disponíveis (não reservados).');
            $table->unsignedBigInteger('reserved_balance')->default(0)->comment('Créditos reservados em execuções pendentes.');
            $table->unsignedBigInteger('lifetime_purchased')->default(0);
            $table->unsignedBigInteger('lifetime_consumed')->default(0);
            $table->timestamps();

            $table->unique('entity_id', 'ai_credit_wallets_entity_unique');
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignUuid('medical_record_id')->nullable()->constrained('medical_records')->nullOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('workflow', 100);
            $table->string('mode', 20);
            $table->string('risk_level', 20)->default('low');
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('estimated_credits')->default(0);
            $table->unsignedBigInteger('reserved_credits')->default(0);
            $table->unsignedBigInteger('consumed_credits')->default(0);
            $table->json('input_summary')->nullable();
            $table->longText('final_output')->nullable();
            $table->json('safety_notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'status', 'created_at'], 'ai_runs_entity_status_created_idx');
            $table->index(['entity_id', 'mode', 'created_at'], 'ai_runs_entity_mode_created_idx');
        });

        Schema::create('ai_credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained('ai_credit_wallets')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('type', 20)->comment('grant|purchase|reserve|consume|release|refund|adjustment|expire');
            $table->bigInteger('amount')->default(0)->comment('Delta do saldo disponível. Pode ser negativo.');
            $table->unsignedBigInteger('balance_after')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('idempotency_key', 'ai_credit_ledger_entries_idempotency_unique');
            $table->index(['entity_id', 'created_at'], 'ai_credit_ledger_entries_entity_created_idx');
            $table->index(['wallet_id', 'created_at'], 'ai_credit_ledger_entries_wallet_created_idx');
            $table->index(['entity_id', 'type'], 'ai_credit_ledger_entries_entity_type_idx');
        });

        Schema::create('ai_run_provider_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();
            $table->string('provider', 20)->comment('openai|anthropic|gemini');
            $table->string('model', 120);
            $table->string('role', 20)->comment('generator|reviewer|adjudicator|fallback');
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('tool_calls_count')->default(0);
            $table->decimal('raw_cost_usd', 14, 8)->nullable();
            $table->unsignedBigInteger('normalized_credits')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['ai_run_id', 'created_at'], 'ai_run_provider_calls_run_created_idx');
            $table->index(['provider', 'model'], 'ai_run_provider_calls_provider_model_idx');
        });

        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 20);
            $table->string('model', 120);
            $table->decimal('input_usd_per_million', 14, 8);
            $table->decimal('output_usd_per_million', 14, 8);
            $table->decimal('reasoning_usd_per_million', 14, 8)->nullable();
            $table->decimal('tool_call_usd', 14, 8)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model', 'effective_from'], 'ai_model_prices_provider_model_effective_unique');
            $table->index(['provider', 'model', 'active'], 'ai_model_prices_provider_model_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
        Schema::dropIfExists('ai_run_provider_calls');
        Schema::dropIfExists('ai_credit_ledger_entries');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_credit_wallets');
    }
};
