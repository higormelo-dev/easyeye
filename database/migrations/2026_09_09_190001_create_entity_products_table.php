<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item de estoque DA CLÍNICA (produto/material/insumo) — App\Models\EntityProduct.
 *
 * `qty_on_hand` e `cost_avg` são DENORMALIZADOS de propósito: são o saldo
 * corrente e o custo médio ponderado, mantidos exclusivamente por
 * App\Services\Stock\StockService::registerMovement() sob lock de linha
 * (nunca via ->update() direto no controller) — ver doc do service. Cada
 * alteração desses dois campos gera uma linha correspondente em
 * stock_movements (trilha de auditoria do saldo).
 *
 * `is_opm`/`requires_lot` são metadados preparados para a Fase 2
 * (rastreabilidade de lote/validade para órteses/próteses/materiais
 * especiais) — nesta fase (1) apenas armazenados, sem enforcement de lote.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('entity_products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('product_category_id')->nullable()
                ->constrained('product_categories')->nullOnDelete();

            $table->string('code'); // interno, auto-gerado (HasEntityCode)
            $table->string('sku')->nullable(); // código externo/fabricante/barras
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('un'); // App\Enums\StockUnit

            $table->boolean('is_opm')->default(false);
            $table->boolean('requires_lot')->default(false);

            $table->decimal('cost_avg', 12, 4)->default(0);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->decimal('min_qty', 12, 3)->default(0);
            $table->decimal('max_qty', 12, 3)->nullable();
            $table->decimal('qty_on_hand', 12, 3)->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'active']);
            $table->index(['entity_id', 'product_category_id']);
            // qty_on_hand <= min_qty => alerta de estoque baixo (consultado por
            // job/relatório da Fase 2) — índice cobre o filtro sem exigir scan.
            $table->index(['entity_id', 'qty_on_hand']);
            $table->unique(['entity_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_products');
    }
};
