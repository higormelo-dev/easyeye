<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Substitui os campos escalares diagnosis_cid10 (string 10) e
 * diagnosis_description (text) por um único campo JSON diagnosis_cids,
 * que armazena um array de objetos {code, description}.
 *
 * Isso permite que o médico registre múltiplos diagnósticos CID-10
 * em um único prontuário, conforme prática clínica comum.
 *
 * Migração de dados: registros existentes com diagnosis_cid10 preenchido
 * são convertidos para o novo formato JSON automaticamente.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->json('diagnosis_cids')->nullable()->after('observation_of_lenses');
        });

        // Migra dados existentes para o novo formato JSON
        DB::table('medical_records')
            ->whereNotNull('diagnosis_cid10')
            ->lazyById()
            ->each(function ($record) {
                DB::table('medical_records')
                    ->where('id', $record->id)
                    ->update([
                        'diagnosis_cids' => json_encode([
                            ['code' => $record->diagnosis_cid10, 'description' => $record->diagnosis_description ?? ''],
                        ]),
                    ]);
            });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['diagnosis_cid10', 'diagnosis_description']);
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('diagnosis_cid10', 10)->nullable()->after('observation_of_lenses');
            $table->text('diagnosis_description')->nullable()->after('diagnosis_cid10');
        });

        // Restaura o primeiro CID do array (melhor esforço)
        DB::table('medical_records')
            ->whereNotNull('diagnosis_cids')
            ->lazyById()
            ->each(function ($record) {
                $cids = json_decode($record->diagnosis_cids, true);
                $first = $cids[0] ?? null;
                if ($first) {
                    DB::table('medical_records')
                        ->where('id', $record->id)
                        ->update([
                            'diagnosis_cid10'       => substr($first['code'] ?? '', 0, 10),
                            'diagnosis_description' => $first['description'] ?? null,
                        ]);
                }
            });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('diagnosis_cids');
        });
    }
};
