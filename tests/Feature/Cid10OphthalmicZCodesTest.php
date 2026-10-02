<?php

use App\Enums\ClientRule;
use App\Models\{Cid10Code, Entity, User};

/**
 * Códigos Z oftalmológicos (Z01.0 "Exame dos olhos e da visão" etc.) entram
 * no catálogo via migration, para bancos já semeados receberem os códigos.
 */
function cidZDoctor(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $doctor = User::factory()->create();

    return [$doctor, createEntityUser($entity, $doctor, ClientRule::Doctor->value)];
}

it('disponibiliza Z01.0 no catálogo após as migrations', function () {
    expect(Cid10Code::where('code', 'Z01.0')->value('description'))
        ->toBe('Exame dos olhos e da visão');
});

it('encontra Z01.0 pela busca de CID por código e por descrição', function () {
    [$doctor, $entityUser] = cidZDoctor();

    foreach (['Z01', 'z01.0', 'exame dos olhos'] as $q) {
        $codes = $this->actingAs($doctor)
            ->withSession(panelSession($entityUser))
            ->getJson(route('panel.cid10.search', ['q' => $q]))
            ->assertOk()
            ->json('*.code');

        expect($codes)->toContain('Z01.0');
    }
});

it('é idempotente ao rodar a migration de novo', function () {
    Cid10Code::where('code', 'Z01.0')->update(['description' => 'Editado']);

    $migration = require database_path('migrations/2026_10_02_100000_add_ophthalmic_z_codes_to_cid10_codes.php');
    $migration->up();

    expect(Cid10Code::where('code', 'Z01.0')->count())->toBe(1)
        ->and(Cid10Code::where('code', 'Z01.0')->value('description'))->toBe('Editado');
});
