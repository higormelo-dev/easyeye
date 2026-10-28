<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal do Paciente — Fase 1 (fundação: conta + login).
 *
 * Conta com senha PRÓPRIA do paciente, vinculada 1:1 a `people` (nunca à
 * tabela `users`, que é 100% staff/ACL entity-scoped). `people.national_registry`
 * (CPF) já é único GLOBALMENTE — ver PatientService::findOrCreatePerson() — logo
 * uma única PatientAccount por pessoa já cobre todas as clínicas onde ela foi
 * atendida, sem precisar de tabela de vínculo adicional nesta fase.
 *
 * Guard dedicado ("patient", ver config/auth.php) + provider próprio: mesmo
 * princípio de isolamento já usado pelo guard "integrator" (tabela e provider
 * próprios, separados de `users`).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Auditoria (HasAuditColumns) — mesma convenção retroativa aplicada
            // a `users`/`patients`/etc. em 2026_03_22_200003_add_audit_columns_to_tables.
            // Fica null na maioria dos casos: a conta é criada pelo PRÓPRIO paciente
            // ao aceitar o convite (sem sessão staff no guard "web"), e
            // AuditContext::userId() só resolve auth()->user() do guard default.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            // 1:1 com people — UNIQUE garante uma única conta por pessoa física,
            // reforçando a nível de banco a regra "um login para todas as clínicas".
            $table->foreignUuid('person_id')->unique()->constrained('people')->cascadeOnDelete();

            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();

            // Reserva de schema para 2FA (Fase futura) — SEM lógica implementada
            // nesta entrega, apenas a coluna para não exigir migration depois.
            $table->boolean('two_factor_enabled')->default(false);

            // Kill-switch de suporte: desativa o acesso sem excluir a conta.
            // Verificado tanto no login quanto em EnsurePatientAuthenticated
            // (revogação em tempo real, não só bloqueio de logins futuros).
            $table->boolean('active')->default(true);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_accounts');
    }
};
