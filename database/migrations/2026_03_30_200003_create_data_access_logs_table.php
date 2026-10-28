<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de acesso (leitura) a dados sensíveis.
 * CFM Res. 2.227/2018 — todo acesso ao prontuário eletrônico deve ser registrado.
 * LGPD Art. 37 — operadores devem manter registro das operações de tratamento.
 *
 * Diferença do audit_logs: registra LEITURA (view/access), não apenas mudanças.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('data_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Recurso acessado (polymorphic: MedicalRecord, PatientExam, People...)
            $table->string('resource_type');
            $table->uuid('resource_id');

            // Identificação do paciente associado ao acesso
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            // Finalidade do acesso (DataAccessPurpose enum)
            $table->string('purpose');

            // Justificativa — obrigatória para EmergencyAccess
            $table->text('justification')->nullable();

            // Contexto da requisição
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('accessed_at')->useCurrent();

            $table->index(['resource_type', 'resource_id']);
            $table->index(['entity_id', 'accessed_at']);
            $table->index(['patient_id', 'accessed_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_access_logs');
    }
};
