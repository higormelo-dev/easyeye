<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-FILL (revisão pós-Fase 4 do módulo de estoque) — vínculo OPCIONAL e
 * ADITIVO entre o inventário de lentes IOL da clínica (entity_iol_lenses,
 * feature já viva — calculadora de lente/agendamento cirúrgico) e o novo
 * catálogo de estoque (entity_products).
 *
 * Decisão explícita do usuário (unificação completa dos dois catálogos
 * traria risco real de migração numa feature em produção — não vale o
 * ganho agora): NENHUM dado existente de entity_iol_lenses muda de
 * significado, NENHUMA coluna é removida, NENHUM fluxo atual quebra.
 * `entity_product_id` fica nullable — só clínicas que quiserem rastrear
 * saldo/lote/custo físico de uma lente específica associam manualmente via
 * tela de Configurações > Lentes IOL. Sem o vínculo, tudo continua
 * exatamente como antes desta migration.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->foreignUuid('entity_product_id')->nullable()->after('iol_lens_model_id')
                ->constrained('entity_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entity_product_id');
        });
    }
};
