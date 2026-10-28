<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Visto/não visto por exame no Gerenciador de Imagens: `viewed_at` guarda
 * QUANDO o exame foi exibido no visualizador pela primeira vez (nulo = nunca
 * visto). Não guarda quem viu. A gravação é um UPDATE atômico `whereNull`
 * (EyeImagesController::markExamViewed) — sem evento de model, então não gera
 * linha de auditoria nem mexe em updated_at. Sem índice nesta versão.
 *
 * Backfill: os exames que já existem nascem "vistos" (viewed_at = created_at).
 * Sem isso, o primeiro deploy marcaria todo o histórico da clínica como "não
 * visualizado" e o indicador/filtro perderia o sentido. Só exames criados daqui
 * em diante começam nulos.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('active');
        });

        DB::table('patient_exams')->whereNull('viewed_at')->update(['viewed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
