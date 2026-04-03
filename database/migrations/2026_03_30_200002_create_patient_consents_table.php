<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de consentimentos do paciente.
 * LGPD Art. 7, I (dados pessoais) e Art. 11, I (dados sensíveis de saúde).
 * Art. 8 — consentimento deve ser livre, informado e inequívoco.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();

            // Tipo e base legal do consentimento
            $table->string('consent_type');   // ConsentType enum
            $table->string('legal_basis');    // LegalBasis enum
            $table->string('status');         // 'granted' | 'revoked'

            // Versão do documento de política de privacidade vigente na coleta
            $table->string('document_version', 20)->nullable();

            // Canal de coleta do consentimento
            $table->string('channel', 30)->default('in_person'); // in_person | digital | phone

            // Quem coletou e quando
            $table->foreignUuid('granted_by')->nullable()->constrained('entity_users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();

            // Revogação
            $table->foreignUuid('revoked_by')->nullable()->constrained('entity_users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            // Rastreabilidade de origem digital
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'patient_id']);
            $table->index(['patient_id', 'consent_type', 'status']);
            $table->index('granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
