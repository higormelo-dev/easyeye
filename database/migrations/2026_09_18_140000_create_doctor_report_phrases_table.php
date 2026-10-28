<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca de frases rápidas do médico pro laudo do Gerenciador de
 * Imagens (benchmark contra concorrente Ger Exames/iWayBrasil — adaptação
 * segura do "Wizard" deles: aqui a tabela nasce vazia, sem seeder de
 * terminologia clínica; o médico constrói a própria biblioteca).
 *
 * Mesmo shape de ai_doctor_prompts (2026_06_12_010000) — prompt de IA e
 * frase de laudo são o mesmo tipo de dado (label + texto + ordem, por
 * médico), só o consumidor final muda.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::create('doctor_report_phrases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('label', 120);
            $table->text('content');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['doctor_id', 'position'], 'doctor_report_phrases_doctor_position_idx');
            $table->index(['entity_id', 'doctor_id'], 'doctor_report_phrases_entity_doctor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_report_phrases');
    }
};
