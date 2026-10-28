<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExamKit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Kits de exames PADRÃO do EasyEye (globais, entity_id nulo) — Fase 2 / B2.
 *
 * Códigos TUSS da Tabela 22 da ANS, conferidos em 07/10/2026 na Tabela 22
 * completa (API oficial de terminologia da ANS) e na planilha "Correlação
 * TUSS (Tab. 22.202609) × Rol RN 465/2021" (gov.br/ans, 14/08/2026); os
 * mesmos códigos de TissReferenceSeeder. Sem código confiável, o item fica
 * SEM código (vai como texto no pedido):
 *  - risco cirúrgico: não é exame oftalmológico — avaliação clínica/
 *    cardiológica faturada pelo outro profissional;
 *  - aberrometria e pupilometria: não há código de exame na Tabela 22.
 * Observações para o médico validar: OCT (41501144) tem cobertura pela DUT
 * 69 (suspeita de glaucoma, hipertensão ocular, edema macular, tratamento
 * antiangiogênico); biometria ÓPTICA tem código próprio (41501276), fora do
 * Rol — o kit usa a ultrassônica (41501012), que está no Rol; a clínica troca
 * na cópia dela se fizer óptica.
 *
 * Idempotente: cria o kit pelo `slug` quando não existe; NÃO sobrescreve um
 * kit global já existente (o manager pode tê-lo ajustado).
 */
class ExamKitsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->kits() as $order => $kit) {
            $exists = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->where('slug', $kit['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($kit, $order) {
                $model = ExamKit::query()->withoutGlobalScopes()->create([
                    'entity_id'   => null,
                    'slug'        => $kit['slug'],
                    'name'        => $kit['name'],
                    'description' => $kit['description'],
                    'active'      => true,
                    'sort_order'  => $order,
                ]);

                foreach ($kit['items'] as $index => [$code, $name]) {
                    $model->items()->create(['tuss_code' => $code, 'name' => $name, 'sort_order' => $index]);
                }
            });
        }
    }

    /**
     * @return list<array{slug: string, name: string, description: string, items: list<array{0: ?string, 1: string}>}>
     */
    public function kits(): array
    {
        return [
            [
                'slug'        => 'glaucoma',
                'name'        => 'Glaucoma',
                'description' => 'Investigação e acompanhamento de glaucoma.',
                'items'       => [
                    ['40103137', 'Campimetria computadorizada'],
                    ['41501144', 'Tomografia de coerência óptica (OCT) de nervo óptico'],
                    ['41501128', 'Paquimetria ultrassônica'],
                    ['41301242', 'Gonioscopia'],
                    ['41301315', 'Retinografia'],
                ],
            ],
            [
                'slug'        => 'pre_op_catarata',
                'name'        => 'Pré-operatório de catarata',
                'description' => 'Exames para indicação e planejamento da cirurgia de catarata.',
                'items'       => [
                    ['41501012', 'Biometria ultrassônica'],
                    ['41301269', 'Microscopia especular de córnea'],
                    ['41301080', 'Topografia de córnea (ceratoscopia computadorizada) / ceratometria'],
                    ['41301250', 'Mapeamento de retina'],
                    [null, 'Risco cirúrgico (avaliação clínica/cardiológica)'],
                ],
            ],
            [
                'slug'        => 'retina_diabetica',
                'name'        => 'Retina diabética',
                'description' => 'Rastreio e acompanhamento da retinopatia diabética.',
                'items'       => [
                    ['41301315', 'Retinografia'],
                    ['41501144', 'Tomografia de coerência óptica (OCT) de mácula'],
                    ['41301250', 'Mapeamento de retina'],
                ],
            ],
            [
                'slug'        => 'ceratocone',
                'name'        => 'Ceratocone',
                'description' => 'Diagnóstico e acompanhamento do ceratocone.',
                'items'       => [
                    ['41301080', 'Topografia/tomografia de córnea'],
                    ['41501128', 'Paquimetria ultrassônica'],
                    ['41301269', 'Microscopia especular de córnea'],
                ],
            ],
            [
                'slug'        => 'refrativa',
                'name'        => 'Cirurgia refrativa',
                'description' => 'Avaliação pré-operatória de cirurgia refrativa.',
                'items'       => [
                    ['41301080', 'Topografia de córnea'],
                    ['41501128', 'Paquimetria ultrassônica'],
                    [null, 'Aberrometria'],
                    [null, 'Pupilometria'],
                ],
            ],
        ];
    }
}
