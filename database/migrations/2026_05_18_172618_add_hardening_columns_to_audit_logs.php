<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening Manager SaaS — endurece audit_logs para suportar:
 *
 *  - reason             : justificativa obrigatória em ações destrutivas
 *                         (cancel subscription, block-access, destroy entity).
 *                         LGPD/CFM: auditor precisa responder "por quê".
 *
 *  - target_entity_id   : entity alvo da ação (separado de entity_id, que é o
 *                         contexto da sessão do admin no momento do log).
 *                         Crítico no Manager SaaS: o admin tem entity_id="saas"
 *                         na sessão, mas a ação afeta entity_id da clínica X.
 *
 *  - target_user_id     : usuário alvo (impersonação, ban, role change).
 *                         Pareado com user_id (quem executou) para a pergunta
 *                         "quem fez o quê a quem".
 *
 *  - status_code        : HTTP status da resposta (200, 403, 422, 500…).
 *                         Permite separar acesso bem-sucedido de tentativa
 *                         negada — sinal crítico para detectar abuso.
 *
 *  - route_name         : nome da rota Laravel acionada. Indexado para queries
 *                         "todas as cancelaments de assinatura no período".
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('new_values');
            $table->foreignUuid('target_entity_id')->nullable()->after('entity_id')
                ->constrained('entities')->nullOnDelete();
            $table->foreignUuid('target_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('status_code')->nullable()->after('event');
            $table->string('route_name', 150)->nullable()->after('status_code');

            $table->index(['route_name', 'created_at']);
            $table->index(['target_entity_id', 'created_at']);
            $table->index(['target_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['route_name', 'created_at']);
            $table->dropIndex(['target_entity_id', 'created_at']);
            $table->dropIndex(['target_user_id', 'created_at']);

            $table->dropConstrainedForeignId('target_entity_id');
            $table->dropConstrainedForeignId('target_user_id');
            $table->dropColumn(['reason', 'status_code', 'route_name']);
        });
    }
};
