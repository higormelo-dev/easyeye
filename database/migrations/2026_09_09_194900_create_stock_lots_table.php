<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lote/validade de um item de estoque — App\Models\StockLot. Rastreabilidade
 * exigida pra materiais sanitários/OPM (`entity_products.requires_lot`).
 *
 * `qty_on_hand`/`cost_avg` seguem a MESMA regra de entity_products: fora de
 * mass-assignment, só StockService escreve (sob lock), mantendo o invariante
 * "SUM(stock_lots.qty_on_hand) de um produto == entity_products.qty_on_hand"
 * pra produto lot-tracked — ver App\Services\Stock\StockService.
 *
 * `expiry_date` nullable: nem todo item lot-tracked tem validade (ex.:
 * dispositivo serializado reutilizável) — o rastreio de LOTE ainda vale
 * (rastreabilidade sanitária) mesmo sem data de vencimento.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('entity_product_id')
                ->constrained('entity_products')->cascadeOnDelete();

            $table->string('lot_number');
            $table->date('expiry_date')->nullable();

            $table->decimal('cost_avg', 12, 4)->default(0);
            $table->decimal('qty_on_hand', 12, 3)->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'expiry_date']);
            $table->index(['entity_product_id', 'active']);
            // Evita cadastrar o mesmo número de lote 2x pro mesmo produto por
            // erro de digitação/corrida — ver StockService::findOrCreateLot().
            $table->unique(['entity_product_id', 'lot_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
