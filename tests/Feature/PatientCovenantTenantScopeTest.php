<?php

declare(strict_types=1);

/**
 * Regressão de segurança (achado CRITICAL confirmado, IDOR cross-tenant):
 *
 * 1. PatientRequest::rules() validava `covenant_id` apenas com
 *    ['required_without:type_method', 'uuid', 'exists:covenants,id'] — sem
 *    escopo de entity_id. Covenant tem entity_id nullable (global OU por
 *    clínica), então staff de uma entity conseguia vincular paciente a um
 *    covenant de OUTRA clínica só sabendo o UUID. Os campos irmãos no mesmo
 *    arquivo (skin_id, iris_id) já usavam o padrão correto de escopo — o fix
 *    replica o mesmo padrão para covenant_id.
 *
 * 2. PatientImportService::loadCovenantsMap() rodava
 *    Covenant::pluck('id', 'name') sem where(entity_id) nem
 *    whereNull(deleted_at), indexando TODOS os covenants de TODAS as
 *    entidades num mapa nome → id. Duas clínicas com covenant de mesmo nome
 *    (ex.: "Unimed") colidiam silenciosamente durante a importação em lote —
 *    o import de uma clínica podia gravar o covenant_id da OUTRA. O fix
 *    escopa a query por entity_id (+ global) e propaga o entityId já
 *    disponível em process() para o método.
 */

use App\Models\{Covenant, Entity, Patient, People, User};
use App\Services\PatientImportService;

beforeEach(function () {
    $this->entityA = Entity::factory()->create(['is_client' => true]);
    $this->entityB = Entity::factory()->create(['is_client' => true]);

    $this->staffA      = User::factory()->create();
    $this->entityUserA = createEntityUser($this->entityA, $this->staffA, 'admin');

    $this->covenantOwnA = Covenant::create([
        'entity_id' => $this->entityA->id,
        'code'      => 'CV-A',
        'name'      => 'Amil',
        'active'    => true,
    ]);

    $this->covenantGlobal = Covenant::create([
        'entity_id' => null,
        'code'      => 'CVP-G',
        'name'      => 'Particular',
        'active'    => true,
    ]);

    $this->covenantOtherB = Covenant::create([
        'entity_id' => $this->entityB->id,
        'code'      => 'CV-B',
        'name'      => 'Bradesco Saude',
        'active'    => true,
    ]);
});

function actAsCovenantEntityA($test)
{
    return $test->actingAs($test->staffA)->withSession(panelSession($test->entityUserA));
}

function validPatientPayload(array $overrides = []): array
{
    static $seq = 0;
    $seq++;

    return array_merge([
        'name'              => "Paciente Teste {$seq}",
        'birth_date'        => '1990-01-01',
        'gender'            => 0,
        'marital_status'    => 1,
        'email'             => "paciente.teste.{$seq}@example.com",
        'national_registry' => str_pad((string) (10000000000 + $seq), 11, '0', STR_PAD_LEFT),
        'cellphone'         => '11999990000',
        'whatsapp'          => true,
    ], $overrides);
}

// ── (a) covenant_id de OUTRA entity falha na validação ─────────────────────

test('store: covenant_id de OUTRA entity falha na validacao (422/sessionHasErrors)', function () {
    actAsCovenantEntityA($this)
        ->post(
            route('panel.patients.store'),
            validPatientPayload(['covenant_id' => $this->covenantOtherB->id]),
        )
        ->assertSessionHasErrors('covenant_id');

    expect(Patient::where('covenant_id', $this->covenantOtherB->id)->count())->toBe(0);
});

test('update: covenant_id de OUTRA entity falha na validacao e nao altera o paciente', function () {
    $person  = People::factory()->create(['national_registry' => '99988877766']);
    $patient = Patient::create([
        'entity_id'   => $this->entityA->id,
        'person_id'   => $person->id,
        'covenant_id' => $this->covenantOwnA->id,
        'active'      => true,
    ]);

    actAsCovenantEntityA($this)
        ->put(
            route('panel.patients.update', $patient),
            validPatientPayload(['covenant_id' => $this->covenantOtherB->id, 'active' => true]),
        )
        ->assertSessionHasErrors('covenant_id');

    expect($patient->fresh()->covenant_id)->toBe($this->covenantOwnA->id);
});

// ── (b) covenant_id da propria entity ou global continua funcionando ──────

test('store: covenant_id da propria entity continua funcionando', function () {
    actAsCovenantEntityA($this)
        ->post(
            route('panel.patients.store'),
            validPatientPayload(['covenant_id' => $this->covenantOwnA->id]),
        )
        ->assertSessionDoesntHaveErrors('covenant_id')
        ->assertRedirect();

    expect(Patient::where('covenant_id', $this->covenantOwnA->id)->count())->toBe(1);
});

test('store: covenant_id global (entity_id null) continua funcionando', function () {
    actAsCovenantEntityA($this)
        ->post(
            route('panel.patients.store'),
            validPatientPayload(['covenant_id' => $this->covenantGlobal->id]),
        )
        ->assertSessionDoesntHaveErrors('covenant_id')
        ->assertRedirect();

    expect(Patient::where('covenant_id', $this->covenantGlobal->id)->count())->toBe(1);
});

test('update: covenant_id da propria entity continua funcionando', function () {
    $person  = People::factory()->create(['national_registry' => '88877766655']);
    $patient = Patient::create([
        'entity_id'   => $this->entityA->id,
        'person_id'   => $person->id,
        'covenant_id' => $this->covenantGlobal->id,
        'active'      => true,
    ]);

    actAsCovenantEntityA($this)
        ->put(
            route('panel.patients.update', $patient),
            validPatientPayload(['covenant_id' => $this->covenantOwnA->id, 'active' => true]),
        )
        ->assertSessionDoesntHaveErrors('covenant_id')
        ->assertRedirect();

    expect($patient->fresh()->covenant_id)->toBe($this->covenantOwnA->id);
});

// ── (c) PatientImportService::loadCovenantsMap() escopado por entity ──────

test('loadCovenantsMap: covenant de OUTRA entity com mesmo nome nao vaza no mapa de import', function () {
    // Mesmo nome em maiúsculas (HasUppercaseFields uppercasa 'name' no save)
    // em duas entidades diferentes — cenário exato do achado da auditoria.
    $ownSameName = Covenant::create([
        'entity_id' => $this->entityA->id,
        'code'      => 'CV-A2',
        'name'      => 'Unimed',
        'active'    => true,
    ]);

    $otherSameName = Covenant::create([
        'entity_id' => $this->entityB->id,
        'code'      => 'CV-B2',
        'name'      => 'Unimed',
        'active'    => true,
    ]);

    $service = app(PatientImportService::class);
    $method  = new ReflectionMethod($service, 'loadCovenantsMap');
    $method->setAccessible(true);

    /** @var array<string, string> $mapForA */
    $mapForA = $method->invoke($service, $this->entityA->id);

    expect($mapForA)->toHaveKey('unimed');
    expect($mapForA['unimed'])->toBe($ownSameName->id);
    expect($mapForA['unimed'])->not->toBe($otherSameName->id);

    /** @var array<string, string> $mapForB */
    $mapForB = $method->invoke($service, $this->entityB->id);

    expect($mapForB['unimed'])->toBe($otherSameName->id);
});

test('loadCovenantsMap: covenant global (entity_id null) aparece no mapa de qualquer entity', function () {
    $service = app(PatientImportService::class);
    $method  = new ReflectionMethod($service, 'loadCovenantsMap');
    $method->setAccessible(true);

    $mapForA = $method->invoke($service, $this->entityA->id);
    $mapForB = $method->invoke($service, $this->entityB->id);

    expect($mapForA['particular'] ?? null)->toBe($this->covenantGlobal->id);
    expect($mapForB['particular'] ?? null)->toBe($this->covenantGlobal->id);

    // Covenant exclusivo da entity B nao aparece no mapa da entity A.
    expect($mapForA)->not->toHaveKey(mb_strtolower($this->covenantOtherB->name));
});
