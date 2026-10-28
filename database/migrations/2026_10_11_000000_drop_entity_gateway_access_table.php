<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove o "Acesso por Clínica" dos gateways (entity_gateway_access).
 *
 * Os gateways de pagamento são só do dono do SaaS — cobram as clínicas pela
 * assinatura e pelos pacotes de créditos de IA. Clínica nunca configura nem
 * recebe por gateway, então a tabela que liberava gateway por clínica não tem
 * mais uso (só a tela do manager a lia/gravava, sem efeito na cobrança).
 *
 * down() recria o schema original (2026_04_06_020000), sem as linhas.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('entity_gateway_access');
    }

    public function down(): void
    {
        if (Schema::hasTable('entity_gateway_access')) {
            return;
        }

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
            $table->index(['entity_id', 'enabled'], 'entity_gateway_access_entity_enabled_idx');
        });
    }
};
