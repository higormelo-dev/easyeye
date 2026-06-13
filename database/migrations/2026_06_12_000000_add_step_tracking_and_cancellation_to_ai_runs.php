<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos de rastreamento da etapa em execução e de cancelamento
 * cooperativo ao AiRun.
 *
 * `started_at` marca o início real do processamento (status -> Running),
 * permitindo computar tempo decorrido no painel sem depender de created_at.
 *
 * `current_role` e `current_provider` permitem ao front mostrar feedback rico
 * durante o spinner ("Revisando com Claude…") sem precisar carregar a tabela
 * ai_run_provider_calls.
 *
 * `cancelled_at` é o ponto de coordenação do cancelamento cooperativo: o
 * orchestrator faz refresh + check antes de cada role e aborta no próximo gap
 * via AiRunCancelledException. Em pré-execução (Pending/Reserved), o controller
 * faz a transição direto para Cancelled e libera a reserva.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->string('current_role', 20)->nullable()->after('status');
            $table->string('current_provider', 20)->nullable()->after('current_role');
            $table->timestamp('started_at')->nullable()->after('rejected_at');
            $table->timestamp('cancelled_at')->nullable()->after('started_at');
            $table->foreignUuid('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'started_at', 'current_provider', 'current_role']);
        });
    }
};
