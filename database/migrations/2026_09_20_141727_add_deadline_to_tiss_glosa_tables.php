<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('tiss_glosas', function (Blueprint $table) {
            $table->date('deadline')->nullable()->after('identified_at');
        });

        Schema::table('tiss_glosa_appeals', function (Blueprint $table) {
            $table->date('deadline')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('tiss_glosas', function (Blueprint $table) {
            $table->dropColumn('deadline');
        });

        Schema::table('tiss_glosa_appeals', function (Blueprint $table) {
            $table->dropColumn('deadline');
        });
    }
};
