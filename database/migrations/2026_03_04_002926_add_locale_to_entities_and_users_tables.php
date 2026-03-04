<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Adiciona locale na tabela entities (idioma padrão da empresa)
        Schema::table('entities', function (Blueprint $table) {
            $table->string('locale', 10)->default('pt_BR')->after('active');
        });

        // Adiciona locale na tabela users (idioma preferido do usuário)
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};

