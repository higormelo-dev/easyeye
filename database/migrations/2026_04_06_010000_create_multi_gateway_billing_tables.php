<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('gateways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('code', 50)->unique();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->boolean('supports_subscriptions')->default(true);
            $table->boolean('supports_one_time_charges')->default(true);
            $table->boolean('supports_refunds')->default(true);
            $table->boolean('supports_webhooks')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['active', 'priority'], 'gateways_active_priority_idx');
        });

        Schema::create('gateway_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('gateway_id')->constrained('gateways')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('scope', 20)->default('global');
            $table->string('label', 120)->nullable();
            $table->text('credentials');
            $table->text('webhook_secret')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['gateway_id', 'scope', 'active'], 'gateway_credentials_gateway_scope_active_idx');
            $table->index(['entity_id', 'scope', 'active'], 'gateway_credentials_entity_scope_active_idx');
        });

        Schema::create('tenant_gateway_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('setting_key', 60)->default('default');
            $table->foreignUuid('default_gateway_id')->constrained('gateways')->restrictOnDelete();
            $table->foreignUuid('fallback_gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->json('allowed_gateways')->nullable();
            $table->boolean('fallback_enabled')->default(false);
            $table->boolean('auto_retry_enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'setting_key'], 'tenant_gateway_settings_entity_key_unique');
            $table->index(['entity_id', 'plan_id'], 'tenant_gateway_settings_entity_plan_idx');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUuid('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->string('reference', 64);
            $table->string('external_invoice_id', 255)->nullable();
            $table->string('external_subscription_id', 255)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BRL');
            $table->string('status', 20)->default('pending');
            $table->string('billing_reason', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_gateway_payload')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'reference'], 'invoices_entity_reference_unique');
            $table->unique(['gateway_code', 'external_invoice_id'], 'invoices_gateway_external_invoice_unique');
            $table->index(['entity_id', 'status', 'due_at'], 'invoices_entity_status_due_idx');
            $table->index(['subscription_id', 'status'], 'invoices_subscription_status_idx');
            $table->index('correlation_id', 'invoices_correlation_idx');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->string('external_payment_id', 255)->nullable();
            $table->string('external_invoice_id', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BRL');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->decimal('gateway_fee', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_gateway_payload')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gateway_code', 'external_payment_id'], 'payments_gateway_external_payment_unique');
            $table->index(['entity_id', 'status', 'paid_at'], 'payments_entity_status_paid_idx');
            $table->index(['invoice_id', 'status'], 'payments_invoice_status_idx');
            $table->index('correlation_id', 'payments_correlation_idx');
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignUuid('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('pending');
            $table->string('trigger', 40)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('idempotency_key', 120);
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gateway_code', 'idempotency_key'], 'payment_attempts_gateway_idempotency_unique');
            $table->index(['invoice_id', 'status'], 'payment_attempts_invoice_status_idx');
            $table->index(['entity_id', 'status', 'created_at'], 'payment_attempts_entity_status_created_idx');
            $table->index('correlation_id', 'payment_attempts_correlation_idx');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('gateway_code', 50);
            $table->string('external_event_id', 255)->nullable();
            $table->string('event_type', 120)->nullable();
            $table->string('event_hash', 64);
            $table->string('signature', 255)->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->json('normalized_payload')->nullable();
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->unique(['gateway_code', 'external_event_id'], 'webhook_events_gateway_external_unique');
            $table->unique(['gateway_code', 'event_hash'], 'webhook_events_gateway_hash_unique');
            $table->index(['gateway_code', 'status', 'received_at'], 'webhook_events_gateway_status_received_idx');
            $table->index('correlation_id', 'webhook_events_correlation_idx');
        });

        Schema::create('billing_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignUuid('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->foreignUuid('webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->string('level', 20);
            $table->text('message');
            $table->json('context')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('request_id', 120)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_id', 'created_at'], 'billing_logs_entity_created_idx');
            $table->index('correlation_id', 'billing_logs_correlation_idx');
            $table->index(['gateway_code', 'created_at'], 'billing_logs_gateway_created_idx');
        });

        Schema::create('financial_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('event_type', 60);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('effective_at');
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('source', 40)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'event_type', 'effective_at'], 'financial_events_entity_type_effective_idx');
            $table->index(['invoice_id', 'event_type'], 'financial_events_invoice_type_idx');
            $table->index(['payment_id', 'event_type'], 'financial_events_payment_type_idx');
        });

        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('previous_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignUuid('new_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('previous_gateway_code', 50)->nullable();
            $table->string('new_gateway_code', 50)->nullable();
            $table->string('change_type', 40);
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at');
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'effective_at'], 'subscription_changes_subscription_effective_idx');
            $table->index(['entity_id', 'change_type', 'effective_at'], 'subscription_changes_entity_type_effective_idx');
        });

        Schema::create('cancellations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->string('reason', 40);
            $table->string('source', 40);
            $table->text('notes')->nullable();
            $table->timestamp('effective_at');
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'effective_at'], 'cancellations_subscription_effective_idx');
            $table->index(['entity_id', 'reason', 'effective_at'], 'cancellations_entity_reason_effective_idx');
        });

        Schema::create('gateway_fallback_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('primary_gateway_code', 50);
            $table->string('fallback_gateway_code', 50);
            $table->string('trigger_type', 40);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('max_retries')->default(1);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'plan_id', 'active', 'priority'], 'gateway_fallback_rules_entity_plan_active_idx');
            $table->index(['primary_gateway_code', 'trigger_type', 'active'], 'gateway_fallback_rules_primary_trigger_active_idx');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('pinned_gateway', 50)->nullable()->after('gateway');
            $table->timestamp('gateway_migration_locked_until')->nullable()->after('gateway_payload');
            $table->string('billing_state', 30)->nullable()->after('status');
            $table->text('last_billing_error')->nullable()->after('billing_state');
            $table->foreignUuid('current_invoice_id')->nullable()->after('plan_id')->constrained('invoices')->nullOnDelete();
            $table->timestamp('last_payment_at')->nullable()->after('renewed_at');
            $table->string('idempotency_key', 120)->nullable()->after('gateway_subscription_id');
            $table->uuid('correlation_id')->nullable()->after('idempotency_key');

            $table->index('pinned_gateway', 'subscriptions_pinned_gateway_idx');
            $table->index('billing_state', 'subscriptions_billing_state_idx');
            $table->index('correlation_id', 'subscriptions_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['current_invoice_id']);
            $table->dropIndex('subscriptions_pinned_gateway_idx');
            $table->dropIndex('subscriptions_billing_state_idx');
            $table->dropIndex('subscriptions_correlation_idx');
            $table->dropColumn([
                'pinned_gateway',
                'gateway_migration_locked_until',
                'billing_state',
                'last_billing_error',
                'current_invoice_id',
                'last_payment_at',
                'idempotency_key',
                'correlation_id',
            ]);
        });

        Schema::dropIfExists('gateway_fallback_rules');
        Schema::dropIfExists('cancellations');
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('financial_events');
        Schema::dropIfExists('billing_logs');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('tenant_gateway_settings');
        Schema::dropIfExists('gateway_credentials');
        Schema::dropIfExists('gateways');
    }
};
