<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRÉ-REQUISITO da migração de lentes IOL pro estoque (fase 0 do plano) —
 * fecha um gap de concorrência pré-existente em HasEntityCode::save()
 * (App\Concerns\HasEntityCode): `code` era gerado por leitura do último
 * valor +1, sem nenhuma garantia no banco contra duas criações concorrentes
 * computando o mesmo próximo número — sem esta constraint, o retry
 * adicionado em HasEntityCode::save() não tem o que capturar (nenhum erro
 * de unicidade é disparado, a duplicata simplesmente é gravada em silêncio).
 *
 * Escopo intencionalmente reduzido a `entity_products`: é a única tabela
 * HasEntityCode que este plano de migração passa a escrever em massa (fase
 * 2 — comando de backfill), então é a única onde o risco de colisão
 * concorrente aumenta de fato. As demais ~17 tabelas que usam o mesmo trait
 * continuam com o comportamento antigo (sem índice único) até que uma
 * necessidade concreta justifique estendê-lo — retrofitá-las juntas exigiria
 * checar cada uma por duplicatas pré-existentes antes do ADD CONSTRAINT, o
 * que está fora do escopo desta feature.
 *
 * Se o ADD CONSTRAINT falhar em produção por duplicata já existente, rodar
 * antes:
 *   SELECT entity_id, code, COUNT(*) FROM entity_products
 *   GROUP BY entity_id, code HAVING COUNT(*) > 1;
 * e resolver manualmente (renomear um dos códigos) antes de reaplicar esta
 * migration.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->unique(['entity_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'code']);
        });
    }
};
