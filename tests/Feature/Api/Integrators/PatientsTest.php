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
});
