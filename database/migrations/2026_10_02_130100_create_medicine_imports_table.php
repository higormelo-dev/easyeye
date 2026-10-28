<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das importações do catálogo global de medicamentos (manager →
 * Medicamentos → Importações): quem subiu, quais arquivos, quando e o que
 * mudou — trilha de auditoria de uma carga que altera o catálogo de todas
 * as clínicas de uma vez.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('medicine_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('cmed_file_path');
            $table->string('cmed_original_name');
            $table->string('open_data_file_path')->nullable();
            $table->string('open_data_original_name')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('deactivated_count')->default(0);
            $table->unsignedInteger('skipped_hospital')->default(0);
            $table->unsignedInteger('skipped_inactive_registration')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_imports');
    }
};
