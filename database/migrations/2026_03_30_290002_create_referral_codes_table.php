<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de indicação peer-to-peer.
 * Cada clínica ativa pode gerar um código para indicar outras clínicas.
 * Quando a indicada converte para plano pago, o referenciador é recompensado.
 * Canal de menor CAC: confiança médico-para-médico.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Clínica que originou o código
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            // Código curto exibido ao usuário (ex: "OCULAR2024")
            $table->string('code', 20)->unique();

            // Tipo e valor da recompensa ao referenciador na conversão
            $table->string('reward_type');           // ReferralRewardType enum
            $table->unsignedSmallInteger('reward_value'); // dias (TrialExtension) ou % (Discount)

            // Controles de uso
            $table->unsignedInteger('uses_count')->default(0);
            $table->unsignedInteger('max_uses')->nullable(); // null = ilimitado

            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'active']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
