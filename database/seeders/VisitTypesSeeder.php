<?php

namespace Database\Seeders;

use App\Models\VisitType;
use Illuminate\Database\Seeder;

/**
 * Tipos de consulta GLOBAIS (entity_id null) — lista inicial enxuta do
 * agendamento. A clínica complementa/reativa itens em Configurações.
 *
 * GOTCHA que causou duplicatas em produção: o model tem HasUppercaseName
 * (mutator salva o nome em MAIÚSCULO). O updateOrCreate antigo buscava por
 * Title Case, nunca encontrava o registro salvo e cada rodada do seeder
 * recriava o catálogo inteiro. A chave de busca DEVE estar exatamente como o
 * banco armazena (uppercase) e fixar entity_id null. O unique parcial
 * visit_types_global_name_unique (migration 2026_08_23) garante no banco.
 */
class VisitTypesSeeder extends Seeder
{
    public function run(): void
    {
        $visitTypes = [
            'Consulta',
            'Retorno',
            'Urgência',
            'Avaliação pré-operatória',
            'Avaliação pós-operatória',
            'Segunda opinião',
            'Teleconsulta',
            'Triagem',
        ];

        foreach ($visitTypes as $visitType) {
            VisitType::query()->updateOrCreate(
                // Chave espelha o que o mutator grava: MAIÚSCULO + global.
                ['entity_id' => null, 'name' => mb_strtoupper($visitType, 'UTF-8')],
                ['active' => true],
            );
        }
    }
}
