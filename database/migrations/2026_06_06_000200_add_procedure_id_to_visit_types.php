<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o Tipo de consulta (VisitType) a um procedimento padrão, para que o
 * lançamento de caixa do agendamento já venha com o procedimento (e o preço,
 * via ProcedurePrice) preenchido.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->foreignUuid('procedure_id')->nullable()->after('name')
                ->constrained('procedures')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('procedure_id');
        });
    }
};
