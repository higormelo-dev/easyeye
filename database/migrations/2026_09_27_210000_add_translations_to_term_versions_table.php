<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traduções de cortesia dos documentos legais (ex.: {"en": "..."}).
 *
 * O texto oficial continua em `content` (pt_BR): é ele que vale e que os
 * usuários aceitam (user_term_acceptances aponta para a versão, não para o
 * idioma). A tradução só muda o que a página pública mostra a quem navega em
 * outro idioma, com aviso de que a versão em português prevalece.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('term_versions', function (Blueprint $table) {
            $table->json('translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('term_versions', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
