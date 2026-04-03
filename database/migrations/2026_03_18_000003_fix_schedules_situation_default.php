<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class () extends Migration {
    public function up(): void
    {
        // Converte registros com situation = 0 (inválido) para 1 (Agendado)
        DB::table('schedules')->where('situation', 0)->update(['situation' => 1]);

        Schema::table('schedules', function (Blueprint $table) {
            $table->integer('situation')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->integer('situation')->default(0)->change();
        });
    }
};
