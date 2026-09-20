<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('tiss_glosas', function (Blueprint $table) {
            $table->string('raw_hash', 64)->nullable()->after('glosa_code');
        });
    }

    public function down(): void
    {
        Schema::table('tiss_glosas', function (Blueprint $table) {
            $table->dropColumn('raw_hash');
        });
    }
};
