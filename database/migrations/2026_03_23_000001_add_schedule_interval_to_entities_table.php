<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->unsignedTinyInteger('schedule_interval')->default(15)
                ->comment('Intervalo em minutos entre consultas. Ex: 15, 20, 30.')
                ->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('schedule_interval');
        });
    }
};
