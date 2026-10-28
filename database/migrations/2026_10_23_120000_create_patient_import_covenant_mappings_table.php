<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De-para de convênios da importação de pacientes: o nome como veio na planilha (normalizado) aponta, por clínica,
 * para um convênio do catálogo e, opcionalmente, para um plano. Reaproveitado nas próximas importações.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('patient_import_covenant_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('source_name', 255);
            $table->string('source_name_normalized', 255);
            // Convênios usam soft delete: o cascade só vale em exclusão definitiva.
            $table->foreignUuid('covenant_id')->constrained('covenants')->cascadeOnDelete();
            $table->foreignUuid('covenant_plan_id')->nullable()->constrained('covenant_plans')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_id', 'source_name_normalized']);
            $table->index(['entity_id', 'covenant_id']);
            $table->index('covenant_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_import_covenant_mappings');
    }
};
