<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fechamento de caixa por período (portado do lock/unlock do CashierBook do
 * smart_oftal). Enquanto houver um CashClose ativo cobrindo a data, lançamentos
 * naquele intervalo não podem ser criados nem alterados.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('cash_closes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('closed_at');

            $table->decimal('total_income', 14, 2)->default(0);
            $table->decimal('total_expense', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'period_start', 'period_end'], 'cash_closes_entity_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closes');
    }
};
