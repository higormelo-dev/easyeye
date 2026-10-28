<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening: 2FA opt-in por empresa (entity).
 *
 * Decisão de produto:
 *  - Cada empresa (clínica OU SaaS admin) decide se exige 2FA dos seus usuários.
 *  - Quando ligado: TODOS os usuários acessando aquela entity precisam ter 2FA
 *    habilitado + verificado na sessão.
 *  - Quando desligado: 2FA permanece opcional (usuário pode habilitar por conta).
 *
 * Por que defaultar SaaS = true:
 *  - Admins SaaS têm acesso a TODOS os tenants e dados sensíveis.
 *  - Convenção de mercado (AWS, GCP, Azure): contas admin de plataforma
 *    DEVEM ter 2FA. Defaultar false aqui seria irresponsável.
 *  - Empresas clientes começam com false; admin clínica liga quando quiser.
 *
 * O default funciona apenas para entities NOVAS; o seeder do data
 * migration abaixo marca entities SaaS existentes como true.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->boolean('requires_two_factor')
                ->default(false)
                ->after('active')
                ->comment('Quando true, força 2FA para todos os usuários desta entity.');

            // Auditoria leve: quem ativou e quando. Não usa audit_logs porque
            // queremos exibir essa info no detalhe da entity (UX de gestão).
            $table->timestamp('two_factor_enabled_at')->nullable()->after('requires_two_factor');
            $table->foreignUuid('two_factor_enabled_by')->nullable()->after('two_factor_enabled_at')
                ->constrained('users')->nullOnDelete();
        });

        // Data migration: entities SaaS existentes passam a exigir 2FA por default.
        // Admin SaaS pode desligar manualmente se necessário (audit log captura).
        DB::table('entities')
            ->where('is_client', false)
            ->update([
                'requires_two_factor'   => true,
                'two_factor_enabled_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('two_factor_enabled_by');
            $table->dropColumn(['requires_two_factor', 'two_factor_enabled_at']);
        });
    }
};
