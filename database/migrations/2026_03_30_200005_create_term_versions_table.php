<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versões de documentos legais (Política de Privacidade, Termos de Uso).
 * LGPD Art. 8 — consentimento deve referenciar a versão do documento vigente.
 * Toda alteração na política exige novo aceite dos usuários.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('term_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tipo do documento
            $table->string('type', 50); // privacy_policy | terms_of_service | lgpd_notice | patient_consent_form

            // Versão semântica (ex: "1.0", "1.1", "2.0")
            $table->string('version', 20);

            $table->text('content'); // Conteúdo integral do documento
            $table->text('summary')->nullable(); // Resumo das mudanças em linguagem acessível

            $table->date('effective_from'); // Data de entrada em vigor
            $table->boolean('active')->default(true);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'version']);
            $table->index(['type', 'active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('term_versions');
    }
};
