<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Procedure;
use Illuminate\Database\Seeder;

/**
 * Catálogo base de procedimentos oftalmológicos (system default, entity_id null),
 * portado do smart_oftal. Apenas os procedimentos CLÍNICOS — as antigas linhas
 * financeiras/de caixa do legado (despesas, água/luz, salário, extorno) não entram
 * aqui: no easyeye essas categorias pertencem ao FinancialCashEntry/FinancialCategory.
 *
 * nomo_binocular: 1 = monocular, 2 = binocular.
 * treatment:      2 = consulta, 3 = exame diagnóstico, 4 = cirúrgico/intervencionista.
 */
class ProcedureSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->procedures() as [$code, $name, $monoBinocular, $treatment]) {
            Procedure::query()->firstOrCreate(
                ['entity_id' => null, 'code' => $code],
                [
                    'name'           => $name,
                    'nomo_binocular' => $monoBinocular,
                    'treatment'      => $treatment,
                    'active'         => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array{0:string,1:string,2:int,3:int}>
     */
    private function procedures(): array
    {
        return [
            // ── Consulta / parecer (treatment 2) ──
            ['00010014', 'CONSULTA', 1, 2],
            ['00000004', 'PARECER MÉDICO', 1, 2],

            // ── Exames diagnósticos (treatment 3) ──
            ['50010158', 'TONOMETRIA', 1, 3],
            ['50010042', 'CAMPIMETRIA COMPUTADORIZADA', 2, 3],
            ['50010026', 'CURVA TENSIONAL DIÁRIA', 1, 3],
            ['50010239', 'GONIOSCOPIA', 1, 3],
            ['50010190', 'BIOMETRIA ULTRASSÔNICA', 2, 3],
            ['50010204', 'PAQUIMETRIA ULTRASSÔNICA', 2, 3],
            ['50010212', 'MICROSCOPIA ESPECULAR DE CÓRNEA', 2, 3],
            ['50010220', 'ULTRASSONOGRAFIA OCULAR', 2, 3],
            ['50010263', 'CERATOSCOPIA COMPUTADORIZADA (TOPOGRAFIA)', 2, 3],
            ['50010301', 'BIOMICROSCOPIA DE FUNDO', 2, 3],
            ['50010093', 'MAPEAMENTO DE RETINA', 2, 3],
            ['50010255', 'FUNDOSCOPIA', 1, 3],
            ['50010123', 'RETINOGRAFIA', 2, 3],
            ['50010131', 'ANGIOFLUORESCEINOGRAFIA', 1, 3],
            ['50010140', 'TESTE DE LENTES DE CONTATO', 1, 3],
            ['50020021', 'CURATIVO OFTALMOLÓGICO', 2, 3],

            // ── Cirúrgicos / intervencionistas (treatment 4) ──
            ['50030035', 'PTERÍGIO - EXÉRESE', 2, 4],
            ['50030060', 'TUMOR DE CONJUNTIVA - EXÉRESE', 2, 4],
            ['50030078', 'TRANSPLANTE CONJUNTIVAL', 2, 4],
            ['50030027', 'INFILTRAÇÃO SUBCONJUNTIVAL', 2, 4],
            ['50040049', 'CORPO ESTRANHO CORNEANO - RETIRADA', 2, 4],
            ['50060015', 'CAPSULOTOMIA YAG', 2, 4],
            ['50060040', 'FACECTOMIA COM IMPLANTE DE LIO', 2, 4],
            ['50070061', 'VITRECTOMIA VIA PARS PLANA', 2, 4],
            ['50090020', 'ENUCLEAÇÃO / EVISCERAÇÃO', 2, 4],
            ['50090054', 'INJEÇÃO RETROBULBAR', 2, 4],
            ['50100033', 'CIRURGIA ANTIGLAUCOMATOSA', 2, 4],
            ['50100068', 'IRIDECTOMIA', 2, 4],
            ['50100106', 'IMPLANTE DE VÁLVULA PARA GLAUCOMA', 2, 4],
            ['50110020', 'CIRURGIA DE ESTRABISMO', 2, 4],
            ['50130234', 'EPILAÇÃO DE CÍLIOS', 2, 4],
            ['50130072', 'CORREÇÃO DE ENTRÓPIO', 2, 4],
            ['50130102', 'CORREÇÃO DE PTOSE PALPEBRAL - UNILATERAL', 1, 4],
            ['50130153', 'TUMOR DE PÁLPEBRA - EXÉRESE', 2, 4],
            ['50130242', 'RECONSTRUÇÃO PARCIAL DE PÁLPEBRA', 2, 4],
            ['50130250', 'RECONSTRUÇÃO TOTAL DE PÁLPEBRA', 2, 4],
            ['50130269', 'RECONSTRUÇÃO TOTAL DE SUPERCÍLIO', 2, 4],
            ['50130056', 'REMOÇÃO DE CALÁZIO', 2, 4],
            ['50140019', 'FOTOCOAGULAÇÃO A LASER DE ARGÔNIO', 2, 4],
            ['50140043', 'RETINOPEXIA', 2, 4],
            ['50150022', 'DACRIOCISTORRINOSTOMIA - UNILATERAL', 1, 4],
        ];
    }
}
