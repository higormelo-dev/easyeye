<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sincronização do catálogo de medicamentos direto da fonte oficial (lista
 * de preços CMED + dados abertos da Anvisa), além do envio manual:
 * de onde veio a carga, qual lista (versão/data de publicação/link) e avisos
 * (lista de reserva, dados abertos fora do ar, nada mudou).
 *
 * Arquivos baixados não ficam guardados (são públicos e versionados — a
 * trilha é a versão + o link); por isso o caminho do arquivo vira opcional.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medicine_imports', function (Blueprint $table) {
            $table->string('source', 20)->default('upload')->after('user_id'); // upload | cmed | scheduled
            $table->boolean('force')->default(false)->after('source');
            $table->string('list_version', 60)->nullable()->after('open_data_original_name');
            $table->date('list_published_at')->nullable()->after('list_version');
            $table->string('list_url', 500)->nullable()->after('list_published_at');
            $table->string('open_data_version', 40)->nullable()->after('list_url');
            $table->text('notice')->nullable()->after('error');

            $table->string('cmed_file_path')->nullable()->change();
            $table->string('cmed_original_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medicine_imports', function (Blueprint $table) {
            $table->dropColumn(['source', 'force', 'list_version', 'list_published_at', 'list_url', 'open_data_version', 'notice']);
        });
    }
};
