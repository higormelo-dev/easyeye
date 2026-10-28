<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano do paciente (produto da ANS ou plano próprio da clínica), ao lado do
 * convênio e da carteirinha. Opcional; nulo quando o plano some (nunca
 * apaga o paciente).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignUuid('covenant_plan_id')->nullable()->after('covenant_id')
                ->constrained('covenant_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('covenant_plan_id');
        });
    }
};
