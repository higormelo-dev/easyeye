<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_credit_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('credited_ledger_entry_id')->nullable()->constrained('ai_credit_ledger_entries')->nullOnDelete();
            $table->string('package_code', 50);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('status', 30)->default('pending_payment');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'ai_credit_purchases_idempotency_unique');
            $table->index(['entity_id', 'status', 'created_at'], 'ai_credit_purchases_entity_status_created_idx');
            $table->index(['subscription_id', 'created_at'], 'ai_credit_purchases_subscription_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_purchases');
    }
};
