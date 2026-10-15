<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sugestão de mapeamento do recall (Fase 6): marca, nos exames GLOBAIS da plataforma, qual exame canônico de um
 * protocolo de retorno cada um sugere (campimetria, OCT de mácula...). Só sugestão — a clínica confirma o mapeamento
 * dela. Nunca sobrescreve uma chave já gravada; rodar de novo não muda nada (idempotente). A lista é a do
 * `ExamTypesSeeder`: instalações novas recebem a chave na hora de criar o exame.
 */
return new class() extends Migration {
    /** @var array<string, string> */
    private const BY_NAME = [
        'CAMPIMETRIA COMPUTADORIZADA'             => 'visual_field',
        'OCT DE NERVO ÓPTICO'                     => 'oct_optic_nerve',
        'OCT DE CAMADA DE FIBRAS NERVOSAS (RNFL)' => 'oct_optic_nerve',
        'OCT MÁCULA'                              => 'oct_macula',
        'RETINOGRAFIA COLORIDA'                   => 'retinography',
        'RETINOGRAFIA'                            => 'retinography',
        'TOPOGRAFIA CORNEANA'                     => 'corneal_topography',
    ];

    public function up(): void
    {
        foreach (self::BY_NAME as $name => $key) {
            DB::table('exam_types')->whereNull('entity_id')->where('name', $name)->whereNull('canonical_key')->update(['canonical_key' => $key]);
        }
    }

    public function down(): void
    {
        DB::table('exam_types')->whereNull('entity_id')->whereIn('name', array_keys(self::BY_NAME))->update(['canonical_key' => null]);
    }
};
