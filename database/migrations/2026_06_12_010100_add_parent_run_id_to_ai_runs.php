<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 3, P2 — Reanalisar com modo superior.
 *
 * parent_run_id liga uma execução à execução anterior que a originou via
 * "escalate" (Economy → Validated → Consensus). Permite auditoria CFM/LGPD
 * e exibir histórico de reanálises no painel.
 *
 * `nullOnDelete` preserva a corrente: se o parent for purgado por GDPR, os
 * filhos continuam acessíveis (sem ponteiro para o original).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->foreignUuid('parent_run_id')->nullable()->after('approved_by')
                ->constrained('ai_runs')->nullOnDelete();

            $table->index(['parent_run_id'], 'ai_runs_parent_run_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_parent_run_idx');
            $table->dropConstrainedForeignId('parent_run_id');
        });
    }
};
