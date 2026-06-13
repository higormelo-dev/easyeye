<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNull('locale')->update(['locale' => 'pt_BR']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable(false)->default('pt_BR')->change();
        });
    }
};
