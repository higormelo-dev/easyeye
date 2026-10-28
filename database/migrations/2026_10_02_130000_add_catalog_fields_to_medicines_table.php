<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

/**
 * Catálogo global de medicamentos gerenciado pelo dono do SaaS (manager →
 * Medicamentos), alimentado pela lista de preços da CMED/Anvisa (uma linha
 * por APRESENTAÇÃO, chave estável = código GGREM) além dos itens curados à
 * mão (OphthalmicMedicinesSeeder, com posologia sugerida).
 *
 * search_text: nome + princípio ativo + concentração + forma + apresentação
 * + laboratório, minúsculo e sem acento (preenchido pela aplicação) — a busca
 * do receituário compara cada palavra digitada contra ele. Índice trigram
 * (pg_trgm) pra LIKE '%termo%' continuar rápido com ~20 mil apresentações.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('active_ingredient', 1000)->nullable()->after('name');
            $table->string('concentration', 255)->nullable()->after('active_ingredient');
            $table->string('pharmaceutical_form', 100)->nullable()->after('concentration');
            $table->string('presentation_detail', 500)->nullable()->after('pharmaceutical_form');
            $table->string('laboratory', 255)->nullable()->after('presentation_detail');
            $table->string('anvisa_registration', 20)->nullable()->after('laboratory');
            $table->string('ean', 20)->nullable()->after('anvisa_registration');
            $table->string('regulatory_category', 60)->nullable()->after('ean');
            $table->string('therapeutic_class', 255)->nullable()->after('regulatory_category');
            $table->boolean('is_ophthalmic')->default(false)->after('therapeutic_class');
            $table->boolean('is_marketed')->default(true)->after('is_ophthalmic');
            $table->string('source', 20)->default('manual')->after('is_marketed');
            $table->string('source_code', 40)->nullable()->after('source');
            // Precisão de microssegundos: a varredura de desativação compara com o
            // carimbo da importação atual (duas cargas no mesmo segundo).
            $table->timestamp('source_synced_at', 6)->nullable()->after('source_code');
            $table->text('search_text')->nullable()->after('source_synced_at');

            // Reimportação idempotente: (cmed, GGREM) identifica a apresentação.
            // source_code nulo (itens manuais) não colide — NULL é distinto em
            // índice único.
            $table->unique(['source', 'source_code'], 'medicines_source_code_unique');
            $table->index(['entity_id', 'active'], 'medicines_entity_active_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX medicines_search_text_trgm_idx ON medicines USING gin (search_text gin_trgm_ops)');
        }

        // Itens já existentes (curados/da clínica) ganham search_text: nome +
        // apresentação ("Colírio"), minúsculo e sem acento.
        DB::table('medicines')
            ->leftJoin('medicine_presentations', 'medicine_presentations.id', '=', 'medicines.medicine_presentation_id')
            ->select('medicines.id', 'medicines.name', 'medicine_presentations.name as presentation_name')
            ->orderBy('medicines.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $text = trim($row->name . ' ' . $row->presentation_name);

                    DB::table('medicines')->where('id', $row->id)->update([
                        'search_text' => trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($text), 'UTF-8'))),
                    ]);
                }
            }, 'medicines.id', 'id');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS medicines_search_text_trgm_idx');
        }

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropUnique('medicines_source_code_unique');
            $table->dropIndex('medicines_entity_active_idx');
            $table->dropColumn([
                'active_ingredient', 'concentration', 'pharmaceutical_form', 'presentation_detail',
                'laboratory', 'anvisa_registration', 'ean', 'regulatory_category', 'therapeutic_class',
                'is_ophthalmic', 'is_marketed', 'source', 'source_code', 'source_synced_at', 'search_text',
            ]);
        });
    }
};
