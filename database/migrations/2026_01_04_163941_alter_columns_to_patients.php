<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->uuid('skin_id')->nullable()->change();
            $table->uuid('iris_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * NÃO reimpõe NOT NULL: uma vez que existam pacientes reais sem tipo de
     * pele/íris cadastrado (fluxo normal — são dados clínicos opcionais, e o
     * import de planilha nem os preenche), forçar NOT NULL de volta quebra
     * com "column contains null values" em qualquer `migrate:rollback`/
     * `migrate:reset` que chegue até aqui (achado real: bateu 2x num
     * `migrate:reset` local em 2026-09-25). Reverter pra "nullable" nunca foi
     * seguro de desfazer sem inventar um valor default falso pros pacientes
     * existentes — deixar nullable no down() é o estado correto, não uma
     * gambiarra.
     */
    public function down(): void
    {
        // Intencionalmente vazio — ver docblock acima.
    }
};
