<?php

namespace Database\Seeders;

use App\Models\VisualAcuityType;
use Illuminate\Database\Seeder;

class VisualAcuityTypesSeeder extends Seeder
{
    /**
     * Catálogo global de acuidade visual, ordenado por `scale` (o prontuário
     * lista `orderBy('scale')`): NO TEST primeiro (opção "não testado"),
     * depois da MELHOR (20/15) para a PIOR (20/400), e por fim os métodos
     * qualitativos (conta dedos, vultos, PL, SPL).
     *
     * updateOrCreate por (entity_id null + name) — não firstOrCreate por
     * (name + scale) como era antes: assim uma renumeração de `scale` (ex.:
     * a inserção do 20/15, que empurrou todo mundo +1) CORRIGE os registros
     * globais já existentes em bancos antigos em vez de duplicá-los. O escopo
     * entity_id IS NULL garante que um tipo CUSTOMIZADO de clínica com o
     * mesmo nome nunca é tocado.
     */
    public function run(): void
    {
        $types = [
            ['scale' => 0, 'name' => 'NO TEST'],
            ['scale' => 1, 'name' => '20/15'],
            ['scale' => 2, 'name' => '20/20'],
            ['scale' => 3, 'name' => '20/25'],
            ['scale' => 4, 'name' => '20/30'],
            ['scale' => 5, 'name' => '20/40'],
            ['scale' => 6, 'name' => '20/50'],
            ['scale' => 7, 'name' => '20/60'],
            ['scale' => 8, 'name' => '20/70'],
            ['scale' => 9, 'name' => '20/80'],
            ['scale' => 10, 'name' => '20/100'],
            ['scale' => 11, 'name' => '20/125'],
            ['scale' => 12, 'name' => '20/150'],
            ['scale' => 13, 'name' => '20/175'],
            ['scale' => 14, 'name' => '20/200'],
            ['scale' => 15, 'name' => '20/225'],
            ['scale' => 16, 'name' => '20/250'],
            ['scale' => 17, 'name' => '20/275'],
            ['scale' => 18, 'name' => '20/300'],
            ['scale' => 19, 'name' => '20/325'],
            ['scale' => 20, 'name' => '20/350'],
            ['scale' => 21, 'name' => '20/375'],
            ['scale' => 22, 'name' => '20/400'],
            ['scale' => 23, 'name' => 'CONTA DEDOS'],
            ['scale' => 24, 'name' => 'VULTOS'],
            ['scale' => 25, 'name' => 'PL'],
            ['scale' => 26, 'name' => 'SPL'],
        ];

        foreach ($types as $type) {
            VisualAcuityType::query()->updateOrCreate(
                ['entity_id' => null, 'name' => $type['name']],
                ['scale' => $type['scale'], 'active' => true],
            );
        }
    }
}
