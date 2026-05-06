<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona colunas de auditoria ao Medicine (paridade com MedicinePresentation
 * e demais entidades clínicas que usam o trait `HasAuditColumns`).
 *
 * Bug pré-existente: o model `App\Models\Medicine` usa o trait `HasAuditColumns`
 * (e `Auditable`) mas a tabela não tinha as colunas, causando QueryException
 * em qualquer insert. F5 expõe a regressão ao adicionar tests + endpoints novos.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            if (! Schema::hasColumn('medicines', 'created_by')) {
                $table->foreignUuid('created_by')->nullable()->after('active')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('medicines', 'updated_by')) {
                $table->foreignUuid('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('medicines', 'deleted_by')) {
                $table->foreignUuid('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
