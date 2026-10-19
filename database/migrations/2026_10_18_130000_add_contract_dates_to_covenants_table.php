<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigência do contrato com o convênio (só datas, sem hora). Alimenta o aviso
 * de contratos vencidos/a vencer no Dashboard; não bloqueia atendimento nem
 * TISS. Nulas: convênios existentes seguem sem vigência cadastrada.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->date('contract_starts_at')->nullable()->after('active');
            $table->date('contract_ends_at')->nullable()->after('contract_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->dropColumn(['contract_starts_at', 'contract_ends_at']);
        });
    }
};
