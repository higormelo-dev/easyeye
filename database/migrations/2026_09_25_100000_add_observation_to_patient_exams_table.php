<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            // Descrição do exame vinda do próprio equipamento (ex.: "Display:
            // Topo 4-Maps" do .EMR do Keratograph/Pentacam).
            $table->text('observation')->nullable()->after('exam_performed_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropColumn('observation');
        });
    }
};
