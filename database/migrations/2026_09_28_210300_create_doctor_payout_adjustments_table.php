<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes manuais de um fechamento — App\Models\DoctorPayoutAdjustment.
 * amount > 0 = acréscimo (ex.: bônus); amount < 0 = desconto (ex.:
 * adiantamento, imposto retido). Só com o fechamento no status `closed`
 * (antes de pagar); o total líquido não pode ficar negativo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_payout_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignUuid('entity_id')
                ->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('doctor_payout_id')
                ->constrained('doctor_payouts')->cascadeOnDelete();

            $table->string('description');
            $table->decimal('amount', 12, 2);

            $table->timestamps();
            $table->softDeletes();

            $table->index('doctor_payout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_payout_adjustments');
    }
};
