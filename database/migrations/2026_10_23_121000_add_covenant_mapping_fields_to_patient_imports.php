<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Reprocessamento por convênio e retenção dos arquivos da importação de pacientes.
 *
 * - reprocess_of_id/reprocess_lines: o import filho processa só as linhas (do arquivo original) que falharam por convênio;
 * - covenant_error_lines: essas linhas, gravadas pelo job ([] = sem erro de convênio; null = import anterior a este recurso);
 * - covenant_mapping_snapshot: o de-para que o job usou (congelado no confirm/reprocessamento);
 * - files_expire_at/files_purged_at: retenção LGPD do CSV original e do CSV de erros (ambos têm dado de paciente).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_imports', function (Blueprint $table) {
            $table->foreignUuid('reprocess_of_id')->nullable()->after('entity_id')->constrained('patient_imports')->nullOnDelete();
            $table->json('reprocess_lines')->nullable();
            $table->json('covenant_error_lines')->nullable();
            $table->json('covenant_mapping_snapshot')->nullable();
            $table->timestamp('files_expire_at')->nullable()->index();
            $table->timestamp('files_purged_at')->nullable();
        });

        // Uma única reprocessagem ativa por import pai: última barreira contra duas requests concorrentes.
        DB::statement("CREATE UNIQUE INDEX patient_imports_one_active_reprocess ON patient_imports (reprocess_of_id) WHERE reprocess_of_id IS NOT NULL AND status IN ('pending', 'processing')");

        // Imports existentes: prazo de 30 dias a partir do deploy (não de created_at), para não apagar o relatório real
        // antes de o usuário resolver os convênios. files_purged_at fica nulo.
        DB::table('patient_imports')->whereNull('files_expire_at')->update(['files_expire_at' => now()->addDays(30)]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS patient_imports_one_active_reprocess');

        Schema::table('patient_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reprocess_of_id');
            $table->dropColumn(['reprocess_lines', 'covenant_error_lines', 'covenant_mapping_snapshot', 'files_expire_at', 'files_purged_at']);
        });
    }
};
