<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curadoria do catálogo global CID-10 pelo manager (Manager → CID-10).
 *
 * - official_description: texto OFICIAL do DATASUS, guardado à parte — o
 *   importador sempre atualiza este; `description` (o que médicos veem) só é
 *   trocada se não foi editada à mão (description_edited_at nulo).
 * - source: datasus (lista oficial) | custom (criado pelo manager ou fora da
 *   lista oficial — guias TISS podem recusar).
 * - chapter (I a XXII) e group_name: classificação oficial (filtros da tela).
 * - created_by/updated_by: HasAuditColumns (trilha de quem mexeu).
 *
 * Roda ANTES da carga completa (2026_10_09_000000), que já preenche tudo.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('cid10_codes', function (Blueprint $table) {
            $table->string('source', 10)->default('datasus')->after('category');
            $table->text('official_description')->nullable()->after('description');
            $table->string('chapter', 5)->nullable()->after('source');
            $table->string('group_name', 255)->nullable()->after('chapter');
            $table->timestamp('description_edited_at')->nullable();
            $table->foreignUuid('description_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('source');
            $table->index('chapter');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('cid10_codes', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['chapter']);
            $table->dropIndex(['category']);
            $table->dropConstrainedForeignId('description_edited_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['source', 'official_description', 'chapter', 'group_name', 'description_edited_at']);
        });
    }
};
