<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            // ── Cabeçalho ──────────────────────────────────────────────────
            $table->boolean('show_header')->default(true)->after('active');
            $table->boolean('header_show_logo')->default(true)->after('show_header');
            $table->boolean('header_show_name')->default(true)->after('header_show_logo');
            $table->boolean('header_show_address')->default(false)->after('header_show_name');
            $table->boolean('header_show_phone')->default(false)->after('header_show_address');

            // ── Assinatura ──────────────────────────────────────────────────
            $table->boolean('signature_show_name')->default(true)->after('show_signature');
            $table->boolean('signature_show_crm')->default(true)->after('signature_show_name');
            $table->boolean('signature_show_rqe')->default(true)->after('signature_show_crm');

            // ── Rodapé ──────────────────────────────────────────────────────
            $table->boolean('footer_show_address')->default(false)->after('footer_text');
            $table->boolean('footer_show_phone')->default(false)->after('footer_show_address');
        });
    }

    public function down(): void
    {
        Schema::table('report_settings', function (Blueprint $table) {
            $table->dropColumn([
                'show_header', 'header_show_logo', 'header_show_name',
                'header_show_address', 'header_show_phone',
                'signature_show_name', 'signature_show_crm', 'signature_show_rqe',
                'footer_show_address', 'footer_show_phone',
            ]);
        });
    }
};
