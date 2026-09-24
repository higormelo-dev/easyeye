<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->foreignUuid('tiss_operator_id')
                ->nullable()
                ->after('ans_registry')
                ->constrained('tiss_operators')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('covenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiss_operator_id');
        });
    }
};
