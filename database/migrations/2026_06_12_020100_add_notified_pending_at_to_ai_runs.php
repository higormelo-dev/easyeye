<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 4, C5 — Notificação 24h para runs em WaitingApproval.
 *
 * Quando o médico cria uma análise mas esquece de aprovar/rejeitar, o crédito
 * fica reservado indefinidamente. A coluna `notified_pending_at` é o ponto de
 * idempotência do comando `ai:notify-waiting-approval` (diário 07:00):
 *
 *   - NULL: nunca foi notificado → notifica agora.
 *   - < now - 24h: foi notificado ontem → notifica de novo hoje.
 *   - >= now - 24h: já notificado hoje → pula.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->timestamp('notified_pending_at')->nullable()->after('cancelled_at');
            $table->index(['status', 'notified_pending_at'], 'ai_runs_status_notified_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex('ai_runs_status_notified_idx');
            $table->dropColumn('notified_pending_at');
        });
    }
};
