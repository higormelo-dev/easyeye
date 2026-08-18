<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medical_record_evolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Isolamento multi-tenant — evolução pertence à clínica.
            $table->foreignUuid('entity_id')
                ->constrained('entities')
                ->cascadeOnDelete();
            // patient_id direto (além do medical_record_id) para a listagem
            // cronológica do histórico do paciente atravessar TODOS os
            // prontuários sem join — mesmo padrão de medical_record_documentations.
            $table->foreignUuid('patient_id')
                ->constrained('patients')
                ->cascadeOnDelete();
            $table->foreignUuid('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();
            $table->foreignUuid('doctor_id')
                ->constrained('doctors')
                ->cascadeOnDelete();
            // Texto livre da evolução clínica (append-only: sem update na UI —
            // CFM exige imutabilidade de registro clínico após criação).
            $table->longText('content');
            // Auditoria
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Listagem cronológica por paciente é a query dominante.
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_evolutions');
    }
};
