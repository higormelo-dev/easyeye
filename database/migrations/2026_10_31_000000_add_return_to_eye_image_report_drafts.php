<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devolução do rascunho de laudo ao técnico (Fase 5): o médico que revisa um
 * rascunho "Enviado para o médico" pode devolvê-lo com o motivo, em vez de só
 * descartar ou emitir. O status `returned` cabe no string(20) que já existe;
 * aqui entram só o motivo, quando e quem devolveu. O motivo vale até o
 * técnico reenviar (markSubmitted() limpa) — o histórico fica na trilha de
 * auditoria (Auditable).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->text('return_reason')->nullable()->after('submitted_at');
            $table->timestamp('returned_at')->nullable()->after('return_reason');
            $table->foreignUuid('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eye_image_report_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['return_reason', 'returned_at']);
        });
    }
};
