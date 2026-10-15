<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4 — a LIO registrada difere da LIO PLANEJADA do olho (potência ou produto):
 * a divergência só passa com a justificativa do cirurgião, gravada no implante.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('surgical_implants', function (Blueprint $table) {
            $table->text('plan_deviation_reason')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('surgical_implants', function (Blueprint $table) {
            $table->dropColumn('plan_deviation_reason');
        });
    }
};
