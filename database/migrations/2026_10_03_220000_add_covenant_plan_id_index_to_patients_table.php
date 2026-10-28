<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Postgres não indexa FK automaticamente (diferente do MySQL). A gaveta de
     * planos do manager conta pacientes por plano a cada página, a exclusão de
     * plano manual checa o uso e o nullOnDelete procura os pacientes do plano:
     * sem o índice, tudo vira seq scan na tabela de pacientes inteira.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->index('covenant_plan_id', 'patients_covenant_plan_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_covenant_plan_id_idx');
        });
    }
};
