<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Diagnóstico (CID-10) do laudo, no mesmo formato de medical_records.diagnosis_cids:
     * array de {code, description}. Populado manualmente pelo médico no momento da
     * aprovação do laudo de IA (AiRunsController::approve). `jsonb` (não `json` como em
     * medical_records) porque esta coluna entra em cláusula WHERE (filtro de Diagnóstico
     * do Gerenciador de Imagens via whereJsonContains).
     */
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->jsonb('diagnosis_cids')->nullable()->after('laterality');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropColumn('diagnosis_cids');
        });
    }
};
