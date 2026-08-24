<?php

declare(strict_types=1);

use App\Models\{Entity, VisitType};
use Database\Seeders\VisitTypesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Catálogo "Tipo de consulta" — regressão do bug de duplicatas:
 * HasUppercaseName salvava MAIÚSCULO e o seeder buscava Title Case, então
 * cada rodada recriava o catálogo inteiro (3 cópias em produção).
 */
it('rodar o seeder várias vezes NÃO duplica os tipos globais', function () {
    $this->seed(VisitTypesSeeder::class);
    $this->seed(VisitTypesSeeder::class);
    $this->seed(VisitTypesSeeder::class);

    $globals = VisitType::withoutGlobalScopes()
        ->whereNull('entity_id')->whereNull('deleted_at')
        ->pluck('name');

    expect($globals)->toHaveCount(8)
        ->and($globals->duplicates())->toHaveCount(0)
        ->and($globals->sort()->values()->all())->toBe([
            'AVALIAÇÃO PRÉ-OPERATÓRIA',
            'AVALIAÇÃO PÓS-OPERATÓRIA',
            'CONSULTA',
            'RETORNO',
            'SEGUNDA OPINIÃO',
            'TELECONSULTA',
            'TRIAGEM',
            'URGÊNCIA',
        ]);
});

it('[TRAVA DE BANCO] duplicata global é recusada pelo unique parcial', function () {
    $this->seed(VisitTypesSeeder::class);

    expect(fn () => VisitType::withoutGlobalScopes()->create([
        'entity_id' => null,
        'name'      => 'Consulta', // mutator salva CONSULTA → colide
        'active'    => true,
    ]))->toThrow(QueryException::class);
});

it('clínica pode ter tipo próprio com o mesmo nome de um global (a trava é só global)', function () {
    $this->seed(VisitTypesSeeder::class);
    $entity = Entity::factory()->create(['is_client' => true]);

    $own = VisitType::withoutGlobalScopes()->create([
        'entity_id' => $entity->id,
        'name'      => 'Consulta',
        'active'    => true,
    ]);

    expect($own->exists)->toBeTrue();
});
