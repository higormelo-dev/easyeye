<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onda 4, C1 — Soft-delete em ai_doctor_prompts.
 *
 * Médico apaga um template por engano e perdia. Com soft delete o registro
 * sobrevive no DB com deleted_at marcado; a UI continua filtrando (Eloquent
 * remove por default), mas o suporte pode restaurar via `ai:restore-prompt`.
 *
 * Não impacta limite de 5 — o service conta apenas linhas com deleted_at IS NULL
 * (comportamento padrão do Eloquent).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('ai_doctor_prompts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_doctor_prompts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
