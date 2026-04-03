<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de assinatura eletrônica do prontuário.
 * CFM Resolução 2.227/2018 — prontuário eletrônico deve garantir
 * autoria, integridade e imutabilidade após assinatura do médico.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            // Referência ao EntityUser (médico) que assinou o prontuário
            $table->foreignUuid('signed_by')->nullable()->constrained('entity_users')->nullOnDelete()->after('doctor_id');
            $table->timestamp('signed_at')->nullable()->after('signed_by');

            // Hash SHA-256: garante integridade do conteúdo no momento da assinatura
            // Composto por: record_id + doctor_id + signed_at + hash do conteúdo clínico
            $table->string('signature_hash', 64)->nullable()->unique()->after('signed_at');

            // Trava o prontuário contra edições após assinatura
            $table->boolean('is_locked')->default(false)->after('signature_hash');

            $table->index('signed_by');
            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['signed_by']);
            $table->dropIndex(['signed_by']);
            $table->dropIndex(['signed_at']);
            $table->dropColumn(['signed_by', 'signed_at', 'signature_hash', 'is_locked']);
        });
    }
};
