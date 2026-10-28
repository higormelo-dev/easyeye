<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cálculo de lentes de contato vinculado à consulta (saiu do Gerenciador de
 * Imagens para o prontuário). Entradas + resultados recalculados no servidor
 * (App\Services\ContactLensCalculator). Coluna do próprio prontuário: vale a
 * trava da assinatura, o versionamento e a auditoria que já existem.
 * Nullable: nenhum prontuário existente muda.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->json('contact_lens_calculation')->nullable()->after('observation_of_lenses');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('contact_lens_calculation');
        });
    }
};
