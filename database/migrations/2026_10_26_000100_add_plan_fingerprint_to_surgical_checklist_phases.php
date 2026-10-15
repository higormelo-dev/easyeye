<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pausa Cirúrgica (Time Out) amarrada ao plano que ela conferiu.
 *
 *  - `plan_fingerprint`: hash do plano e da janela NO FECHAMENTO da fase (LIO
 *    planejada, técnica, anestesia, olho, equipe, cirurgião, janela, sala).
 *    Se algo mudar depois, a Pausa fechada deixa de valer para registrar a
 *    cirurgia como realizada.
 *  - `override_fingerprint`: o plano/janela que a exceção do cirurgião cobriu.
 *    Mudou de novo → a exceção antiga não vale, precisa de outra.
 *
 * Linhas anteriores à coluna ficam NULL e seguem valendo como sempre valeram.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('surgical_checklist_phases', function (Blueprint $table) {
            $table->char('plan_fingerprint', 64)->nullable()->after('content_hash');
            $table->char('override_fingerprint', 64)->nullable()->after('override_at');
        });
    }

    public function down(): void
    {
        Schema::table('surgical_checklist_phases', function (Blueprint $table) {
            $table->dropColumn(['plan_fingerprint', 'override_fingerprint']);
        });
    }
};
