<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // BUGFIX: MedicalRecordEvolution usa HasAuditColumns + SoftDeletes, mas
        // a migration original (2026_08_17_000000) nunca criou deleted_by —
        // HasAuditColumns::bootHasAuditColumns() tenta setar esse atributo e
        // salvar (saveQuietly()) em toda soft-delete; sem a coluna, o delete
        // quebra com erro SQL. Latente até hoje porque o produto é append-only
        // (sem UI de exclusão), mas é uma bomba-relógio pra qualquer delete
        // futuro (admin via tinker, rotina de expurgo, etc).
        Schema::table('medical_record_evolutions', function (Blueprint $table) {
            $table->foreignUuid('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_record_evolutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
