<?php

namespace Database\Seeders;

use App\Models\ReportCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ReportCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = [
            [
                'name'        => 'Laudos',
                'slug'        => 'laudos',
                'icon'        => 'ti ti-file-report',
                'description' => 'Laudos de exames oftalmológicos (tonometria, ecobiometria, gonioscopia, etc.)',
                'sort_order'  => 1,
            ],
            [
                'name'        => 'Exames Especializados',
                'slug'        => 'exames-especializados',
                'icon'        => 'ti ti-device-desktop-analytics',
                'description' => 'Modelos para exames complementares especializados (OCT, campimetria, topografia, etc.)',
                'sort_order'  => 2,
            ],
            [
                'name'        => 'Receituários',
                'slug'        => 'receituarios',
                'icon'        => 'ti ti-prescription',
                'description' => 'Prescrições de lentes, medicamentos e orientações clínicas.',
                'sort_order'  => 3,
            ],
            [
                'name'        => 'Atestados',
                'slug'        => 'atestados',
                'icon'        => 'ti ti-certificate',
                'description' => 'Atestados médicos e de comparecimento.',
                'sort_order'  => 4,
            ],
            [
                'name'        => 'Procedimentos',
                'slug'        => 'procedimentos',
                'icon'        => 'ti ti-clipboard-list',
                'description' => 'Solicitações de procedimentos, exames laboratoriais e avaliações.',
                'sort_order'  => 5,
            ],
            [
                'name'        => 'Relatórios',
                'slug'        => 'relatorios',
                'icon'        => 'ti ti-report-medical',
                'description' => 'Relatórios oftalmológicos gerais e administrativos.',
                'sort_order'  => 6,
            ],
            [
                'name'        => 'Laser',
                'slug'        => 'laser',
                'icon'        => 'ti ti-focus-2',
                'description' => 'Documentos para procedimentos a laser (YAG, Argônio, etc.).',
                'sort_order'  => 7,
            ],
            [
                'name'        => 'Triagem',
                'slug'        => 'triagem',
                'icon'        => 'ti ti-eye-check',
                'description' => 'Modelos para triagem visual e teste do olhinho.',
                'sort_order'  => 8,
            ],
        ];

        foreach ($categories as $data) {
            ReportCategory::firstOrCreate(
                ['slug' => $data['slug']],
                $data,
            );
        }
    }
}
