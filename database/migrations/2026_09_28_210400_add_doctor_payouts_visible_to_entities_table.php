<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política da clínica: médicos podem ver os próprios repasses ("Meus
 * repasses")? Desligado por padrão — é a clínica (admin) quem decide expor
 * valores ao médico. Mudanças ficam no audit_logs (Entity é Auditable).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('doctor_payouts_visible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('doctor_payouts_visible');
        });
    }
};
