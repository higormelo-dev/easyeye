<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Define substituições dinâmicas para templates de documentação.
 *
 * Exemplos de variáveis disponíveis:
 *   {{PACIENTE_NOME}}      → Patient → person → full_name
 *   {{PACIENTE_CPF}}       → Patient → person → cpf
 *   {{MEDICO_NOME}}        → Doctor → person → full_name
 *   {{MEDICO_CRM}}         → Doctor → record
 *   {{CLINICA_NOME}}       → Entity → name
 *   {{CLINICA_ENDERECO}}   → Entity → address
 *   {{DATA_ATUAL}}         → carbon format d/m/Y
 *   {{DATA_EXTENSO}}       → carbon format por extenso
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('report_setting_variables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_setting_content_id')
                ->constrained('report_setting_contents')
                ->cascadeOnDelete();
            $table->string('placeholder', 100);     // ex: {{PACIENTE_NOME}}
            $table->string('source_type', 30);      // patient | doctor | entity | date | custom
            $table->string('source_field', 100)->nullable();  // campo no model/relation
            $table->string('default_value')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_setting_variables');
    }
};
