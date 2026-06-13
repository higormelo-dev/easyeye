<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Colunas adicionais para rastrear estados de pagamento (refund/chargeback)
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('failed_at');
            $table->timestamp('chargeback_at')->nullable()->after('refunded_at');
        });

        // Adiciona next_billing_at para facilitar scheduler de renovação
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('next_billing_at')->nullable()->after('ends_at');
            $table->timestamp('past_due_at')->nullable()->after('last_payment_at');

            $table->index(['status', 'next_billing_at'], 'subscriptions_status_next_billing_idx');
            $table->index(['billing_state', 'past_due_at'], 'subscriptions_billing_state_past_due_idx');
        });

        // Circuit breaker por gateway (e opcionalmente por tenant)
        Schema::create('gateway_circuit_breakers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('gateway_code', 50)->index();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('state', 20)->default('closed'); // closed | open | half_open
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->unsignedSmallInteger('failure_threshold')->default(5);
            $table->string('last_trigger_type', 40)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('open_until')->nullable();
            $table->timestamps();

            $table->unique(['gateway_code', 'entity_id'], 'gateway_circuit_breakers_gateway_entity_unique');
            $table->index(['gateway_code', 'state', 'open_until'], 'gateway_circuit_breakers_state_idx');
        });

        // Billing retry schedules — rastrear retries agendados de forma auditável
        Schema::create('billing_retry_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('gateway_code', 50)->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->timestamp('scheduled_for');
            $table->timestamp('executed_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | executed | cancelled | skipped
            $table->text('result_message')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for'], 'billing_retry_schedules_status_scheduled_idx');
            $table->index('invoice_id', 'billing_retry_schedules_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_retry_schedules');
        Schema::dropIfExists('gateway_circuit_breakers');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refunded_at', 'chargeback_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_status_next_billing_idx');
            $table->dropIndex('subscriptions_billing_state_past_due_idx');
            $table->dropColumn(['next_billing_at', 'past_due_at']);
        });
    }
};
