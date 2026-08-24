<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Prescrição de lentes com MÚLTIPLAS características (ex.: Multifocal +
 * Antirreflexo): lens_away_ids/lens_near_ids (jsonb, array de ids de lenses)
 * substituem o single na UI. As colunas antigas lens_away_id/lens_near_id
 * ficam e recebem o PRIMEIRO item do array (retrocompat de qualquer
 * consumidor legado). Backfill: single existente vira array de 1.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->jsonb('lens_away_ids')->nullable();
            $table->jsonb('lens_near_ids')->nullable();
        });

        DB::statement('UPDATE medical_records SET lens_away_ids = jsonb_build_array(lens_away_id::text) WHERE lens_away_id IS NOT NULL');
        DB::statement('UPDATE medical_records SET lens_near_ids = jsonb_build_array(lens_near_id::text) WHERE lens_near_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['lens_away_ids', 'lens_near_ids']);
        });
    }
};
