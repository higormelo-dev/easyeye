<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->unsignedTinyInteger('schedule_interval')->nullable()
                ->comment('Intervalo em minutos entre consultas. Nulo = usa o intervalo da entidade.')
                ->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('schedule_interval');
        });
    }
};
