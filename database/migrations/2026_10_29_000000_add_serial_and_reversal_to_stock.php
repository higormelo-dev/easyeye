<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M4 — estoque pronto para rastrear implante.
 *
 *  - `entity_products.requires_serial`: o produto (LIO, anel...) é rastreado
 *    por número de SÉRIE na cirurgia (RDC 556/2021).
 *  - `stock_movements.reversal_of_id`: o estorno de um consumo aponta para o
 *    consumo que desfez. Índice ÚNICO: um consumo só é estornado uma vez (o
 *    ledger continua imutável — o estorno é uma linha nova, de sentido oposto).
 *  - Backfill das lentes IOL já cadastradas: marcam série sempre; marcam lote
 *    só quando o saldo agregado já é o dos lotes (nenhum saldo "sem lote"):
 *    exigir lote de um produto com saldo fora de qualquer lote travaria o
 *    consumo. A cirurgia exige lote e série das LIO de qualquer jeito, pelo
 *    tipo do implante, e nenhum lote é inventado aqui.
 */
return new class() extends Migration {
    public function up(): void
    {
        // hasColumn: o teste do backfill reexecuta up() sobre o esquema já migrado.
        if (! Schema::hasColumn('entity_products', 'requires_serial')) {
            Schema::table('entity_products', function (Blueprint $table) {
                $table->boolean('requires_serial')->default(false)->after('requires_lot');
            });
        }

        if (! Schema::hasColumn('stock_movements', 'reversal_of_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->uuid('reversal_of_id')->nullable()->after('stock_lot_id');
                $table->foreign('reversal_of_id')->references('id')->on('stock_movements');
                $table->unique('reversal_of_id', 'stock_movements_reversal_of_unique');
            });
        }

        DB::statement('
            UPDATE entity_products
               SET requires_serial = TRUE
             WHERE id IN (SELECT entity_product_id FROM entity_iol_lenses WHERE entity_product_id IS NOT NULL)
        ');

        DB::statement('
            UPDATE entity_products p
               SET requires_lot = TRUE
             WHERE p.id IN (SELECT entity_product_id FROM entity_iol_lenses WHERE entity_product_id IS NOT NULL)
               AND p.requires_lot = FALSE
               AND p.qty_on_hand = COALESCE((SELECT SUM(l.qty_on_hand) FROM stock_lots l WHERE l.entity_product_id = p.id AND l.deleted_at IS NULL), 0)
        ');
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_reversal_of_unique');
            $table->dropForeign(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
        });

        Schema::table('entity_products', function (Blueprint $table) {
            $table->dropColumn('requires_serial');
        });
    }
};
