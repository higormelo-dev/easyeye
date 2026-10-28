<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item de pedido de compra — App\Models\PurchaseOrderItem.
 *
 * `quantity_received` é denormalizado e fica FORA de mass-assignment (mesma
 * regra de EntityProduct.qty_on_hand/StockLot.qty_on_hand) — só
 * App\Services\Stock\PurchaseOrderService::receive() escreve nele, sob
 * lock, dentro da mesma transação que gera o stock_movement(purchase_in).
 *
 * `unit_cost`/`subtotal` são SNAPSHOT do momento da criação do pedido —
 * não recalculam se o produto mudar de preço depois (histórico de compra
 * tem que refletir o preço negociado NAQUELE pedido).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')
                ->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('entity_product_id')
                ->constrained('entity_products')->cascadeOnDelete();

            $table->decimal('quantity_ordered', 12, 3);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('subtotal', 14, 2);

            $table->timestamps();

            $table->index(['purchase_order_id']);
            $table->index(['entity_id', 'entity_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
