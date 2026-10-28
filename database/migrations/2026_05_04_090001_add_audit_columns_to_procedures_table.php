<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6 — adiciona colunas de auditoria ao `procedures` (paridade com Medicine).
 *
 * Tabela criada antes do trait `HasAuditColumns` ser adotado no model.
 * Sem isso, qualquer insert em produção quebra com QueryException.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            if (! Schema::hasColumn('procedures', 'created_by')) {
                $table->foreignUuid('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('procedures', 'updated_by')) {
                $table->foreignUuid('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('procedures', 'deleted_by')) {
                $table->foreignUuid('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
