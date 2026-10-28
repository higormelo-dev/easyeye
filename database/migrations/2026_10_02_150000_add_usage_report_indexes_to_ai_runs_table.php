<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager → Uso de IA filtra execuções de TODAS as clínicas por período (e
 * por ação). Os índices existentes começam por entity_id (tela da clínica) e
 * não servem pra essa varredura global.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->index('created_at', 'ai_runs_created_at_idx');
            $table->index(['workflow', 'created_at'], 'ai_runs_workflow_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_workflow_created_idx');
            $table->dropIndex('ai_runs_created_at_idx');
        });
    }
};
