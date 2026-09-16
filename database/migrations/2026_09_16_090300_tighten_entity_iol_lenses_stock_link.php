<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Migração de lentes IOL pro estoque (fase 3 do plano) — ÚLTIMO passo,
 * DESTRUTIVO. PRÉ-CONDIÇÃO OBRIGATÓRIA antes de rodar em qualquer ambiente
 * com dado real: `php artisan iol-lenses:migrate-to-stock` (fase 2) já
 * rodou e `SELECT COUNT(*) FROM entity_iol_lenses WHERE entity_product_id
 * IS NULL` retorna 0. Rodar esta migration antes disso deixa linhas órfãs
 * (NOT NULL vai falhar o ALTER TABLE de propósito, não silenciosamente).
 *
 * Passo 1 é um backup da tabela INTEIRA (`CREATE TABLE ... AS SELECT`)
 * antes de qualquer DROP COLUMN — é a rede de segurança real: o `down()`
 * desta migration readiciona as colunas mas NÃO recupera os dados (não tem
 * como, foram descartados pelo DROP) — restauração de verdade é via
 * `entity_iol_lenses_pre_migration_backup`.
 *
 * `category` (tipo ÓPTICO da lente — monofocal/multifocal/tórica/EDF)
 * permanece — é conceito DIFERENTE de entity_products.product_category_id
 * (categoria AMPLA de estoque, ex. "Lentes IOL"), não duplicação.
 */
return new class() extends Migration {
    public function up(): void
    {
        // Guarda defensiva — falha ALTO e CLARO em vez de deixar o NOT
        // NULL/UNIQUE logo abaixo falhar com um erro genérico de
        // constraint, ou (pior) travar o deploy inteiro sem dizer o
        // próximo passo certo.
        $missing = DB::table('entity_iol_lenses')->whereNull('entity_product_id')->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "entity_iol_lenses tem {$missing} linha(s) sem entity_product_id. ".
                'Rode "php artisan iol-lenses:migrate-to-stock" (e confirme 0 pendências) antes de aplicar esta migration.',
            );
        }

        $duplicated = DB::table('entity_iol_lenses')
            ->select('entity_product_id')
            ->whereNotNull('entity_product_id')
            ->groupBy('entity_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicated > 0) {
            throw new RuntimeException(
                "entity_iol_lenses tem {$duplicated} entity_product_id duplicado(s) entre lentes diferentes — ".
                'a constraint UNIQUE desta migration vai falhar. Resolva as duplicatas manualmente (ver fase 2 do plano '.
                '— nunca fundir silenciosamente, clonar o EntityProduct pra segunda lente) antes de reaplicar.',
            );
        }

        DB::statement('CREATE TABLE entity_iol_lenses_pre_migration_backup AS SELECT * FROM entity_iol_lenses');

        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'model_name', 'price', 'image_path', 'active']);
        });

        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->uuid('entity_product_id')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->dropUnique(['entity_product_id']);
            $table->uuid('entity_product_id')->nullable()->change();
        });

        Schema::table('entity_iol_lenses', function (Blueprint $table) {
            $table->string('manufacturer')->nullable();
            $table->string('model_name')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
        });

        // Dado NÃO volta sozinho — ver docblock da classe. Se precisar
        // restaurar de verdade ANTES de reverter (não depois — dropamos a
        // tabela de backup abaixo), rodar manualmente:
        //   UPDATE entity_iol_lenses e
        //   SET manufacturer = b.manufacturer, model_name = b.model_name,
        //       price = b.price, image_path = b.image_path, active = b.active
        //   FROM entity_iol_lenses_pre_migration_backup b
        //   WHERE e.id = b.id;
        //
        // down() dropa a tabela de backup — reverter esta migration desfaz
        // TODOS os artefatos que up() criou, backup incluso. A rede de
        // segurança real em produção é o backup EXTERNO (pg_dump) citado no
        // plano, não esta tabela auxiliar; mantê-la viva depois de um
        // down() serviria só pra confundir uma clínica que reapliquei-a-
        // migration depois (up() falharia com "table already exists").
        // Isso também é o que permite ciclos up→down→up repetidos em teste.
        Schema::dropIfExists('entity_iol_lenses_pre_migration_backup');
    }
};
