<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            // Agrupa turnos de uma mesma conversa do Assistente Virtual flutuante
            // (workflow=assistant_chat). Cada mensagem do usuário vira um AiRun
            // próprio (mantém 1 run = 1 execução/reserva de crédito, auditoria
            // por chamada de provider), mas todos compartilham conversation_id
            // para o enricher reconstruir o histórico multi-turno.
            // Nullable: workflows existentes (record_assist, eye_image_analysis,
            // etc.) não usam conversa — cada run é isolado, como já era.
            $table->uuid('conversation_id')->nullable()->after('parent_run_id');
            $table->index(['entity_id', 'conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'conversation_id', 'created_at']);
            $table->dropColumn('conversation_id');
        });
    }
};
