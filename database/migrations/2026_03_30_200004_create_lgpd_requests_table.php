<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitações de direitos do titular de dados.
 * LGPD Art. 18 — o titular tem direito a: acesso, correção, anonimização,
 * portabilidade, eliminação, revogação de consentimento e informação.
 * Art. 23 — prazo de resposta: 15 dias.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('lgpd_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();

            // Paciente titular da solicitação (nullable: pode ser usuário sem cadastro)
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            // Dados do solicitante (titular ou representante legal)
            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('requester_document', 14)->nullable(); // CPF

            // Tipo e status
            $table->string('request_type');  // LgpdRequestType enum
            $table->string('status');        // LgpdRequestStatus enum

            $table->text('description');
            $table->text('response')->nullable();
            $table->text('rejection_reason')->nullable();

            // Prazos — LGPD exige resposta em até 15 dias (Art. 23)
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            // Quem tratou a solicitação
            $table->foreignUuid('responded_by')->nullable()->constrained('entity_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['entity_id', 'status']);
            $table->index(['entity_id', 'deadline_at']);
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgpd_requests');
    }
};
