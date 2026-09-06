<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `AuditContext`/`audit_logs`/`data_access_logs` só reconheciam
 * `App\Models\User` como ator — sem esta coluna, toda leitura do PRÓPRIO
 * paciente no Portal (guard "patient", model PatientAccount) gravava
 * `user_id = null`, furando a trilha CFM Res. 2.227/2018 + LGPD Art. 37 pro
 * caso "o titular acessou o próprio documento". Achado bloqueante documentado
 * desde a Fase 1 do plano "Portal do Paciente".
 *
 * Coluna separada de `user_id` (não reaproveitada): paciente e staff nunca se
 * misturam — mesmo princípio de isolamento de guards já aplicado em
 * PatientAccount/EnsurePatientAuthenticated.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignUuid('patient_account_id')->nullable()->after('user_id')
                ->constrained('patient_accounts')->nullOnDelete();
        });

        Schema::table('data_access_logs', function (Blueprint $table) {
            $table->foreignUuid('patient_account_id')->nullable()->after('user_id')
                ->constrained('patient_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_account_id');
        });

        Schema::table('data_access_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_account_id');
        });
    }
};
