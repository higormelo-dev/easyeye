<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registra cada recarga manual que o time do EasyEye faz no painel dos provedores
 * (OpenAI, Anthropic, Google). Usado para calcular saldo estimado restante via:
 *
 *   saldo_estimado(provider) = SUM(amount_usd) - SUM(raw_cost_usd consumido após o primeiro topup)
 *
 * Não armazena dados sensíveis de pagamento — apenas o valor e referência opcional
 * (id da transação no painel do provedor, para auditoria humana).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ai_provider_topups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 20)->comment('openai|anthropic|gemini');
            $table->decimal('amount_usd', 14, 4);
            $table->timestamp('topped_up_at');
            $table->string('reference', 120)->nullable()->comment('ID da transação no painel do provedor');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider', 'topped_up_at'], 'ai_provider_topups_provider_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_topups');
    }
};
