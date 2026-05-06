<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6 — Catálogo de Indicações Clínicas (paridade smart_oftal `indications`).
 *
 * Modelagem mínima: descrição + escopo opcional por entidade (entity_id null
 * representa registros globais, igual a Procedure/Medicine).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('indications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')
                ->nullable()
                ->constrained('entities')
                ->cascadeOnDelete();
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indications');
    }
};
