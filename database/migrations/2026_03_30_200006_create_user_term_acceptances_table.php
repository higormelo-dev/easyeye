<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro do aceite dos documentos legais por usuário.
 * LGPD Art. 8, §5 — ônus da prova do consentimento é do controlador.
 * Este registro é a prova auditável do aceite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_term_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('term_version_id')->constrained('term_versions')->cascadeOnDelete();

            $table->timestamp('accepted_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Evita duplo aceite da mesma versão
            $table->unique(['user_id', 'term_version_id']);

            $table->index(['user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_term_acceptances');
    }
};
