<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula uma movimentação ao lote afetado (quando o produto é lot-tracked).
 * Nullable: produto sem `requires_lot` nunca tem lote — StockService só
 * preenche quando um App\Models\StockLot é passado ao lançar o movimento.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('stock_lot_id')->nullable()->after('entity_product_id')
                ->constrained('stock_lots')->nullOnDelete();

            $table->index(['stock_lot_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_lot_id');
        });
    }
};
