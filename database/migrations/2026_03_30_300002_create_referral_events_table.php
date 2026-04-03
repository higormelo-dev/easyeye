<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos rastreados no funil de indicação.
 * Permite calcular: quantas indicações viraram trial e quantas converteram para pago.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('referral_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();

            // Clínica que foi indicada
            $table->foreignUuid('referred_entity_id')->constrained('entities')->cascadeOnDelete();

            $table->string('event_type'); // ReferralEventType enum

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['referral_code_id', 'referred_entity_id', 'event_type']);
            $table->index(['referral_code_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
    }
};
