<?php

namespace Database\Seeders;

use App\Models\VisitType;
use Illuminate\Database\Seeder;

class VisitTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $visitTypes = [
            'Consulta médica', 'Consulta de retorno',
            'Consulta de urgência/emergência', 'Exame oftalmológico',
            'Exame complementar', 'Mapeamento de retina',
            'Consulta pré-operatória', 'Consulta pós-operatória',
            'Procedimento ambulatorial', 'Procedimento terapêutico',
            'Triagem', 'Avaliação técnica/enfermagem',
            'Avaliação para óculos ou lentes', 'Atendimento de óptica',
            'Atendimento administrativo', 'Teleconsulta',
        ];

        foreach ($visitTypes as $visitType) {
            $name = mb_convert_case($visitType, MB_CASE_TITLE, 'UTF-8');

            VisitType::query()->updateOrCreate(
                ['name' => $name],
                ['active' => true],
            );
        }
    }
}
