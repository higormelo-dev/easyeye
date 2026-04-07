<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controla quais gateways de pagamento de TENANT cada clínica pode usar.
 *
 * Lógica de acesso:
 *   - Sem registro  → clínica NÃO pode usar o gateway
 *   - enabled = 1   → clínica PODE configurar e usar o gateway
 *   - enabled = 0   → acesso temporariamente suspenso (sem apagar histórico)
 *
 * Gerenciado exclusivamente pelo painel do SaaS owner (/manager/gateways).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('entity_gateway_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('gateway_id')->constrained('gateways')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'gateway_id'], 'entity_gateway_access_unique');
            $table->index(['gateway_id', 'enabled'], 'entity_gateway_access_gateway_enabled_idx');
            $table->index(['entity_id', 'enabled'],  'entity_gateway_access_entity_enabled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_gateway_access');
    }
};
