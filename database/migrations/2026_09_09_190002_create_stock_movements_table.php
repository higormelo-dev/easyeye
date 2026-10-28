<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger IMUTÁVEL de movimentação de estoque — App\Models\StockMovement.
 *
 * Sem SoftDeletes/update de propósito: uma movimentação nunca é editada nem
 * apagada (corromperia a trilha de auditoria do saldo). Uma correção lança
 * uma NOVA movimentação em sentido oposto — ver
 * App\Services\Stock\StockService. Único ponto de escrita nesta tabela.
 *
 * `balance_after` é um SNAPSHOT do saldo do produto logo após este
 * movimento — evita recalcular o histórico inteiro (SUM) toda vez que o
 * extrato precisa exibir "saldo em cada ponto do tempo".
 *
 * `reference_type`/`reference_id` é uma referência polimórfica solta (sem
 * FK — o alvo varia: procedimento executado, guia TISS, pedido de compra)
 * usada nas Fases 3/4 para linkar a baixa/entrada à sua origem de negócio.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
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

            $table->string('type'); // App\Enums\StockMovementType
            $table->decimal('quantity', 12, 3); // sempre positiva; a direção vem do type
            $table->decimal('unit_cost', 12, 4)->nullable(); // custo unitário do lançamento (entradas)
            $table->decimal('balance_after', 12, 3); // saldo do produto após este movimento

            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();

            $table->text('note')->nullable();
            $table->timestamp('occurred_at'); // data do FATO (pode ser retroativa); created_at = data do LANÇAMENTO

            $table->timestamps();

            $table->index(['entity_id', 'entity_product_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
