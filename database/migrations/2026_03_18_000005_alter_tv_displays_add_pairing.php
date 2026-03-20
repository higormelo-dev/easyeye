<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tv_displays', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('name');
            $table->string('pin', 6)->nullable()->after('token');
            $table->string('token', 64)->nullable()->change();
        });

        // TVs existentes já estão ativas
        DB::table('tv_displays')->whereNull('status')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('tv_displays', function (Blueprint $table) {
            $table->dropColumn(['status', 'pin']);
        });

        DB::table('tv_displays')->whereNull('token')->update(['token' => DB::raw("gen_random_uuid()")]);

        Schema::table('tv_displays', function (Blueprint $table) {
            $table->string('token', 64)->nullable(false)->change();
        });
    }
};
