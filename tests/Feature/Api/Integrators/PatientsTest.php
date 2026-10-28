<?php

use App\Models\{Patient, People};

describe('GET /api/integrators/v1/patients', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists patients belonging to the entity', function () {
        Patient::factory(3)->create(['entity_id' => $this->ctx['entity']->id]);

        $this->getJson('/api/integrators/v1/patients', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('does not return patients from other entities', function () {
        $other = setupIntegrator();

        Patient::factory(5)->create(['entity_id' => $other['entity']->id]);
        Patient::factory(2)->create(['entity_id' => $this->ctx['entity']->id]);

        $this->getJson('/api/integrators/v1/patients', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('filters patients by name search', function () {
        $person = People::factory()->create(['full_name' => 'João da Silva']);
        Patient::factory()->create([
            'entity_id' => $this->ctx['entity']->id,
            'person_id' => $person->id,
        ]);
        // BUGFIX (flaky, achado ao rodar a suite completa): faker pt_BR pode
        // sortear 'João' como nome de qualquer pessoa aleatória (é um dos
        // nomes masculinos mais comuns no provider) — sem full_name fixo, o
        // decoy vira um match falso-positivo com probabilidade real, dando 2
        // resultados em vez de 1 de forma intermitente.
        $decoy = People::factory()->create(['full_name' => 'Maria Aparecida Souza']);
        Patient::factory()->create([
            'entity_id' => $this->ctx['entity']->id,
            'person_id' => $decoy->id,
        ]);

        $response = $this->getJson('/api/integrators/v1/patients?search=João', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('filters patients by code search', function () {
        $target = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $response = $this->getJson(
            "/api/integrators/v1/patients?search={$target->code}",
            $this->ctx['headers'],
        )->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('filters patients by import_code search', function () {
        $target = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
        $target->forceFill(['import_code' => 'LEGACY-SEARCH-1'])->save();
        Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $response = $this->getJson('/api/integrators/v1/patients?search=LEGACY-SEARCH', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('filters patients by card_number search', function () {
        Patient::factory()->create([
            'entity_id'   => $this->ctx['entity']->id,
            'card_number' => 'CARD-ESPECIAL-999',
        ]);
        Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);

        $response = $this->getJson('/api/integrators/v1/patients?search=CARD-ESPECIAL', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('exposes the person full_name in patient attributes', function () {
        // O integrador desktop usa full_name como segundo fator na
        // identificação por OCR (cruza nome lido com o paciente casado por código).
        $person = People::factory()->create(['full_name' => 'Carlos Eduardo Pereira']);
        Patient::factory()->create([
            'entity_id' => $this->ctx['entity']->id,
            'person_id' => $person->id,
        ]);

        // People normaliza full_name para maiúsculas ($uppercaseFields):
        // compara com o valor persistido, não com o literal.
        $this->getJson('/api/integrators/v1/patients', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.0.attributes.full_name', $person->fresh()->full_name);
    });

    it('caps per_page at the plan limit', function () {
        Patient::factory(10)->create(['entity_id' => $this->ctx['entity']->id]);

        $response = $this->getJson('/api/integrators/v1/patients?per_page=999', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/patients')
            ->assertUnauthorized();
    });
});

describe('GET /api/integrators/v1/patients/{id}', function () {
    beforeEach(function () {
        $this->ctx     = setupIntegrator();
        $this->patient = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    });

    it('shows patient by UUID', function () {
        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->patient->id]);
    });

    it('shows patient by code', function () {
        $this->getJson("/api/integrators/v1/patients/{$this->patient->code}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->patient->id]);
    });

    it('exposes the person full_name when showing patient by code', function () {
        $person  = People::factory()->create(['full_name' => 'Ana Beatriz Lima']);
        $patient = Patient::factory()->create([
            'entity_id' => $this->ctx['entity']->id,
            'person_id' => $person->id,
        ]);

        $this->getJson("/api/integrators/v1/patients/{$patient->code}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.attributes.full_name', $person->fresh()->full_name);
    });

    it('shows patient by integer number', function () {
        $numericPart = (int) substr($this->patient->code, 4); // remove 'PAC-'

        $this->getJson("/api/integrators/v1/patients/{$numericPart}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->patient->id]);
    });

    it('returns 404 for patient from another entity', function () {
        $other   = setupIntegrator();
        $patient = Patient::factory()->create(['entity_id' => $other['entity']->id]);

        $this->getJson("/api/integrators/v1/patients/{$patient->id}", $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 404 for non-existent patient', function () {
        $this->getJson('/api/integrators/v1/patients/PAC-NAOEXISTE', $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->getJson("/api/integrators/v1/patients/{$this->patient->id}")
            ->assertUnauthorized();
    });

    it('shows patient by import_code (string)', function () {
        $this->patient->forceFill(['import_code' => 'LEGACY-042'])->save();

        $this->getJson('/api/integrators/v1/patients/LEGACY-042', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->patient->id])
            ->assertJsonPath('data.attributes.import_code', 'LEGACY-042');
    });

    it('shows patient by import_code (puramente numerico, sistema legado)', function () {
        // import_code numérico não pode ser engolido pela heurística de
        // "número puro = número do código PAC" — precisa tentar os dois.
        $this->patient->forceFill(['import_code' => '778899'])->save();

        $this->getJson('/api/integrators/v1/patients/778899', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->patient->id]);
    });

    it('nao mistura import_code de outra entidade', function () {
        $other        = setupIntegrator();
        $otherPatient = Patient::factory()->create(['entity_id' => $other['entity']->id]);
        $otherPatient->forceFill(['import_code' => 'SHARED-CODE'])->save();

        $this->getJson('/api/integrators/v1/patients/SHARED-CODE', $this->ctx['headers'])
            ->assertNotFound();
    });
});
