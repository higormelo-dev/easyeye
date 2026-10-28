<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grant polimórfico de visibilidade de um documento clínico (laudo, exame ou
 * anexo) pro Portal do Paciente — Fase 2 do plano "Portal do Paciente".
 *
 * entity_id + patient_id ficam EXPLÍCITOS na própria linha (defesa em
 * profundidade) em vez de exigir navegar `shareable->patient->entity` em toda
 * checagem — mesma lição da auditoria de 38 IDOR desta sessão: um campo a
 * menos pra esquecer de checar.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_document_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();

            // Documento compartilhado: MedicalRecordDocumentation | PatientExam | MedicalRecordFile.
            $table->uuidMorphs('shareable');

            // Quem concedeu e quando
            $table->foreignUuid('granted_by')->nullable()->constrained('entity_users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();

            // Revogação
            $table->foreignUuid('revoked_by')->nullable()->constrained('entity_users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'revoked_at']);
            $table->index(['shareable_type', 'shareable_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_document_shares');
    }
};
