<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcação "urgente" no exame (Gerenciador de Imagens): o médico sinaliza que
 * o exame precisa de laudo com prioridade. Ordena a lista (urgentes primeiro)
 * e é auditado como qualquer outra alteração do PatientExam. Sem índice nesta
 * versão.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->boolean('urgent')->default(false)->after('viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropColumn('urgent');
        });
    }
};
