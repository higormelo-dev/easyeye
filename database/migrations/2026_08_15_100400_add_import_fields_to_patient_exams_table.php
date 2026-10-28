<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Campos de origem do exame para suportar importação manual de exame
     * externo no Gerenciador de Imagens. `source` é validado pela aplicação
     * via App\Enums\ExamSource (sem check constraint no banco).
     */
    public function up(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->string('source')->default('integrator')->after('diagnosis_cids');
            $table->string('external_origin')->nullable()->after('source');
            $table->timestamp('exam_performed_at')->nullable()->after('external_origin');
            $table->uuid('import_batch_id')->nullable()->after('exam_performed_at');

            $table->index('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('patient_exams', function (Blueprint $table) {
            $table->dropIndex(['import_batch_id']);
            $table->dropColumn(['source', 'external_origin', 'exam_performed_at', 'import_batch_id']);
        });
    }
};
