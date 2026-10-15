<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Módulo cirúrgico (Fase 4) / M3a — modelos de TCLE e de orientações
 * pré-operatórias da clínica.
 *
 *  - `type`: consent | pre_op_guidance (valores de SurgeryDocumentType).
 *  - `category`: categoria de cirurgia (SurgeryType::$categories) que o modelo
 *    atende; NULL = serve para qualquer cirurgia.
 *  - `default_key`: chave do modelo padrão do sistema de onde a linha nasceu
 *    ("Adotar modelos padrão"). A adoção é idempotente: nunca recria nem
 *    sobrescreve uma linha com a mesma chave (inclusive personalizada,
 *    inativada ou apagada pela clínica).
 *  - `version`: sobe a cada mudança do corpo; a emissão guarda a versão usada.
 *    O documento emitido é um retrato congelado — editar o modelo depois não o
 *    altera.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('surgery_consent_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('category')->nullable();
            $table->string('name', 150);
            $table->longText('body');
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->string('default_key', 60)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['entity_id', 'type', 'active'], 'surgery_templates_entity_type_idx');
        });

        // Um modelo padrão por clínica (apagado também conta: não ressuscita).
        DB::statement('
            CREATE UNIQUE INDEX surgery_templates_default_key_unique
                ON surgery_consent_templates (entity_id, default_key)
             WHERE default_key IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('surgery_consent_templates');
    }
};
