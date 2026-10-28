<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_documentations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();
            $table->foreignUuid('patient_id')
                ->constrained('patients')
                ->cascadeOnDelete();
            $table->foreignUuid('doctor_id')
                ->constrained('doctors')
                ->cascadeOnDelete();
            $table->foreignUuid('report_setting_id')
                ->nullable()
                ->constrained('report_settings')
                ->nullOnDelete();
            $table->foreignUuid('report_setting_content_id')
                ->nullable()
                ->constrained('report_setting_contents')
                ->nullOnDelete();
            // Tipo de documentação (armazenado para histórico mesmo se template for deletado)
            $table->string('type', 30)->default('prescription');
            $table->string('title')->nullable();
            // Conteúdo final com variáveis já substituídas (snapshot)
            $table->longText('content');
            // Auditoria
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_documentations');
    }
};
