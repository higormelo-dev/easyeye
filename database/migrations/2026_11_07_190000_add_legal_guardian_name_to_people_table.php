<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD Art. 14 / G9 — identificação do responsável legal quando o paciente é menor.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('legal_guardian_name')->nullable()->after('father_name');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('legal_guardian_name');
        });
    }
};
