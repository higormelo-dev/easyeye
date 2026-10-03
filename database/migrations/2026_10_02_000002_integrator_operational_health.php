<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('integrator_queue_health', function (Blueprint $table) {
            $table->json('operational')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integrator_queue_health', function (Blueprint $table) {
            $table->dropColumn('operational');
        });
    }
};
