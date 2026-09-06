<?php

/**
 * Dashboard "Minhas Clínicas" (item 9 do escopo) — cobre o risco de
 * segurança "Dashboard do paciente NUNCA deve expor prontuário/exame/
 * documento" e a regra central da Fase 1: uma única PatientAccount por
 * person_id enxerga as Patient rows de TODAS as clínicas onde a pessoa foi
 * atendida, e NENHUMA linha de outro person_id.
 */

use App\Models\{Entity, Patient, PatientAccount, People};

test('dashboard retorna as clinicas de TODAS as entidades do titular, e nenhuma de outra pessoa', function () {
    $entityA = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica A']);
    $entityB = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica B']);
    $entityC = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica C']);

    $person      = People::factory()->create(['full_name' => 'Paciente Teste']);
    $outroPerson = People::factory()->create();

    // O mesmo person_id atendido em duas clínicas diferentes — ACHADO CENTRAL
    // do plano: Patient::where('person_id', ...) já cobre isso sem tabela nova.
    Patient::factory()->create(['entity_id' => $entityA->id, 'person_id' => $person->id]);
    Patient::factory()->create(['entity_id' => $entityB->id, 'person_id' => $person->id]);

    // Paciente de OUTRA pessoa na clínica C — nunca deve aparecer para esta conta.
    Patient::factory()->create(['entity_id' => $entityC->id, 'person_id' => $outroPerson->id]);

    $account = PatientAccount::factory()->create(['person_id' => $person->id]);
    loginAsPatient($account);

    // inertiaHeaders(): resposta Inertia pura em JSON (sem Blade/@vite) —
    // mesmo contorno já usado em tests/Feature/AI/AiUsageDashboardTest.php
    // para não depender de `npm run build` ter rodado neste ambiente. Nesse
    // modo (X-Inertia: true) a asserção é via assertJsonPath/json(), não
    // assertInertia() — o helper assertInertia() desta versão do pacote só
    // funciona contra o Blade renderizado (assertViewHas('page')).
    $response = $this->get(route('patient-portal.dashboard'), inertiaHeaders());

    $response->assertOk();
    $response->assertJsonPath('component', 'PatientPortal/Dashboard');
    // People::setAttribute uppercase automaticamente full_name — compara com
    // o valor real persistido, não com o literal passado à factory.
    $response->assertJsonPath('props.patientName', $person->fresh()->full_name);
    $response->assertJsonCount(2, 'props.clinics');

    $entityIds = collect($response->json('props.clinics'))
        ->pluck('entity_id')
        ->sort()
        ->values()
        ->all();

    expect($entityIds)->toEqualCanonicalizing([$entityA->id, $entityB->id]);
});

test('dashboard nao expõe nenhum dado clinico — apenas entity_id/name/city', function () {
    $entity = Entity::factory()->create(['is_client' => true]);
    $person = People::factory()->create();

    Patient::factory()->create(['entity_id' => $entity->id, 'person_id' => $person->id]);

    $account = PatientAccount::factory()->create(['person_id' => $person->id]);
    loginAsPatient($account);

    $response = $this->get(route('patient-portal.dashboard'), inertiaHeaders());

    $clinic = $response->json('props.clinics.0');

    expect(array_keys($clinic))->toEqualCanonicalizing(['entity_id', 'name', 'city', 'clinic_url']);
});

test('rota do dashboard sem sessao patient nega acesso, nunca vaza dados', function () {
    $this->getJson(route('patient-portal.dashboard'))->assertUnauthorized();
});
