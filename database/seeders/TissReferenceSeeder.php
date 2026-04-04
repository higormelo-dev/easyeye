<?php

declare(strict_types = 1);

namespace Database\Seeders;

use App\Domains\Tiss\Models\{TissOperator, TissTussCode, TissVersion};
use Illuminate\Database\Seeder;

class TissReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVersions();
        $this->seedOperators();
        $this->seedTussCodes();
    }

    private function seedVersions(): void
    {
        $versions = [
            [
                'code' => '202601',
                'layout_version' => '01.06.00',
                'effective_from' => '2026-01-01',
                'active' => true,
            ],
            [
                'code' => '202603',
                'layout_version' => '04.03.00',
                'effective_from' => '2026-03-01',
                'active' => true,
            ],
        ];

        foreach ($versions as $version) {
            TissVersion::query()->updateOrCreate(
                ['code' => $version['code']],
                [
                    'layout_version' => $version['layout_version'],
                    'effective_from' => $version['effective_from'],
                    'active' => $version['active'],
                    'metadata' => [
                        'seeded_by' => self::class,
                    ],
                ]
            );
        }
    }

    private function seedOperators(): void
    {
        $operators = [
            [
                'ans_code' => '326305',
                'name' => 'AMIL ASSISTÊNCIA MÉDICA INTERNACIONAL S.A.',
                'trade_name' => 'AMIL',
            ],
            [
                'ans_code' => '359017',
                'name' => 'UNIMED NACIONAL - COOPERATIVA CENTRAL',
                'trade_name' => 'UNIMED',
            ],
            [
                'ans_code' => '005711',
                'name' => 'BRADESCO SAÚDE S.A.',
                'trade_name' => 'BRADESCO SAÚDE',
            ],
        ];

        foreach ($operators as $operator) {
            TissOperator::query()->updateOrCreate(
                ['ans_code' => $operator['ans_code']],
                [
                    'name' => $operator['name'],
                    'trade_name' => $operator['trade_name'],
                    'active' => true,
                    'metadata' => [
                        'seeded_by' => self::class,
                    ],
                ]
            );
        }
    }

    private function seedTussCodes(): void
    {
        $codes = [
            [
                'code' => '10101012',
                'table_code' => '22',
                'description' => 'CONSULTA EM CONSULTÓRIO',
            ],
            [
                'code' => '30301053',
                'table_code' => '22',
                'description' => 'BIOMICROSCOPIA DE FUNDO DE OLHO',
            ],
            [
                'code' => '30301150',
                'table_code' => '22',
                'description' => 'TONOMETRIA',
            ],
            [
                'code' => '30301206',
                'table_code' => '22',
                'description' => 'MAPEAMENTO DE RETINA',
            ],
            [
                'code' => '30301230',
                'table_code' => '22',
                'description' => 'RETINOGRAFIA COLORIDA BINOCULAR',
            ],
            [
                'code' => '30301257',
                'table_code' => '22',
                'description' => 'TOMOGRAFIA DE COERÊNCIA ÓPTICA',
            ],
        ];

        foreach ($codes as $code) {
            TissTussCode::query()->updateOrCreate(
                [
                    'code' => $code['code'],
                    'table_code' => $code['table_code'],
                ],
                [
                    'description' => $code['description'],
                    'effective_from' => '2026-01-01',
                    'active' => true,
                    'metadata' => [
                        'seeded_by' => self::class,
                    ],
                ]
            );
        }
    }
}
