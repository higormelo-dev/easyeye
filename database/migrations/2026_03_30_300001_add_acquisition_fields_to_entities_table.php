<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de atribuição de aquisição na clínica.
 * Registra qual parceiro trouxe a clínica e qual código de indicação foi usado.
 * Essencial para calcular o CAC por canal e medir ROI de cada canal de venda.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            // Parceiro/revendedor que trouxe esta clínica (atribuição de canal)
            $table->foreignUuid('partner_id')->nullable()->after('is_client')
                ->constrained('partners')->nullOnDelete();

            // Código de indicação usado no cadastro (indicação peer-to-peer)
            $table->foreignUuid('referral_code_id')->nullable()->after('partner_id')
                ->constrained('referral_codes')->nullOnDelete();

            $table->index('partner_id');
            $table->index('referral_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropForeign(['referral_code_id']);
            $table->dropIndex(['partner_id']);
            $table->dropIndex(['referral_code_id']);
            $table->dropColumn(['partner_id', 'referral_code_id']);
        });
    }
};
