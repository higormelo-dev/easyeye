<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reabertura de caixa com motivo: só admin da clínica reabre, e a reabertura
 * grava quem, quando e por quê na própria linha (o soft delete continua sendo
 * "período reaberto"). Aditiva: fechamentos já reabertos ficam com os campos
 * nulos — o histórico deles segue no audit_logs.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('cash_closes', function (Blueprint $table) {
            $table->text('reopen_reason')->nullable()->after('notes');
            $table->foreignUuid('reopened_by')->nullable()->after('reopen_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable()->after('reopened_by');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['reopen_reason', 'reopened_at']);
        });
    }
};
