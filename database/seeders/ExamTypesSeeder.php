<?php

namespace Database\Seeders;

use App\Models\ExamType;
use Illuminate\Database\Seeder;

class ExamTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $examsByCategory = [
            1 => [
                'Acuidade visual (longe e perto)',
                'Refração objetiva',
                'Refração subjetiva',
                'Teste do buraco estenopeico',
                'Teste de Ishihara (cores)',
                'Avaliação da motilidade ocular',
                'Avaliação do reflexo pupilar',
                'Biomicroscopia (lâmpada de fenda)',
                'Tonometria (pressão intraocular)',
                'Fundoscopia (oftalmoscopia direta/indireta)',
            ],
            2 => [
                'Refração computadorizada',
                'Refração cicloplegica',
                'Teste de dominância ocular',
                'Adaptação de lentes de contato',
                'Curva base para lente de contato',
                'Avaliação de conforto de lentes',
                'Over-refraction',
            ],
            3 => [
                'Mapeamento de retina',
                'Retinografia colorida',
                'Retinografia red-free',
                'Angiofluoresceinografia',
                'Angiografia por indocianina verde (ICG)',
                'Autofluorescência do fundo de olho',
                'OCT de retina',
                'OCT-A (angiografia por OCT)',
                'Ultrassonografia ocular (modo B)',
            ],
            4 => [
                'Tonometria de aplanação',
                'Paquimetria corneana',
                'Campimetria computadorizada',
                'Campimetria manual',
                'Gonioscopia',
                'OCT de nervo óptico',
                'OCT de camada de fibras nervosas (RNFL)',
                'Curva tensional diária',
                'Teste de provocação para glaucoma',
            ],
            5 => [
                'Topografia corneana',
                'Tomografia corneana',
                'Paquimetria ultrassônica',
                'Paquimetria óptica',
                'Microscopia especular',
                'Teste de Schirmer',
                'Teste do tempo de ruptura do filme lacrimal (BUT)',
                'Coloração com fluoresceína',
                'Coloração com rosa bengala',
            ],
            6 => [
                'Biometria ocular',
                'Biometria óptica',
                'Cálculo de lente intraocular (LIO)',
                'Avaliação do cristalino',
                'Avaliação de opacidades lenticulares',
            ],
            7 => [
                'Teste de Hirschberg',
                'Teste de Krimsky',
                'Teste cover/uncover',
                'Teste alternate cover',
                'Teste de Worth (4 pontos)',
                'Teste de estereopsia (Titmus, Randot)',
                'Avaliação de vergências',
            ],
            8 => [
                'Teste do reflexo vermelho',
                'Teste de Teller',
                'Avaliação de acuidade visual infantil',
                'Screening visual neonatal',
                'Avaliação de ambliopia',
            ],
            9 => [
                'Campimetria para defeitos neurológicos',
                'Avaliação do nervo óptico',
                'Teste de sensibilidade ao contraste',
                'Potencial evocado visual (PEV)',
                'Avaliação pupilar aferente relativa (RAPD)',
            ],
            10 => [
                'Teste de visão de contraste',
                'Teste de visão noturna',
                'Teste de adaptação ao escuro',
                'Teste de glare (ofuscamento)',
                'Microperimetria',
            ],
            11 => [
                'Avaliação pré-operatória oftalmológica',
                'Avaliação pós-operatória',
                'Controle pós-cirurgia refrativa',
                'Controle pós-cirurgia de catarata',
                'Controle pós-injeção intravítrea',
            ],
        ];

        foreach ($examsByCategory as $categoryId => $exams) {
            $this->createExamsForCategory($categoryId, $exams);
        }
    }

    /**
     * Create exam types for a specific category.
     */
    private function createExamsForCategory(int $categoryId, array $exams): void
    {
        foreach ($exams as $examName) {
            ExamType::create([
                'name'     => $examName,
                'category' => $categoryId,
                'active'   => true,
            ]);
        }
    }
}
