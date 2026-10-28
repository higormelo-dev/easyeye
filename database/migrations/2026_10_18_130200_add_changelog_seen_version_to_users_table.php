<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última versão das Novidades (resources/changelog) que o usuário abriu. Fica
 * no banco — e não no navegador — para o badge do menu valer em todos os
 * dispositivos. Nula = nunca abriu.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('changelog_seen_version', 20)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('changelog_seen_version');
        });
    }
};
