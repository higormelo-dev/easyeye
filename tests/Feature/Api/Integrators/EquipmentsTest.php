<?php

use App\Models\EntityIntegratorEquipment;

describe('GET /api/integrators/v1/equipments', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('lists equipments belonging to the integrator', function () {
        EntityIntegratorEquipment::factory(3)->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);

        $this->getJson('/api/integrators/v1/equipments', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    });

    it('does not return equipments from other integrators', function () {
        $other = setupIntegrator();

        EntityIntegratorEquipment::factory(3)->create([
            'integrator_id' => $other['integrator']->id,
        ]);
        EntityIntegratorEquipment::factory(2)->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);

        $this->getJson('/api/integrators/v1/equipments', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    });

    it('filters by search term on name', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'Refrator Modelo X',
        ]);
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'Tonômetro Y',
        ]);

        $response = $this->getJson('/api/integrators/v1/equipments?search=Refrator', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.total'))->toBe(1);
    });

    it('caps per_page at the plan limit', function () {
        EntityIntegratorEquipment::factory(10)->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);

        $response = $this->getJson('/api/integrators/v1/equipments?per_page=999', $this->ctx['headers'])
            ->assertOk();

        expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
    });

    it('returns 401 without authentication', function () {
        $this->getJson('/api/integrators/v1/equipments')
            ->assertUnauthorized();
    });
});

describe('POST /api/integrators/v1/equipments', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('creates a new equipment', function () {
        $payload = [
            'name'          => 'Novo Equipamento',
            'ip'            => '10.0.0.50',
            'mac'           => 'AA:BB:CC:DD:EE:FF',
            'serial_number' => 'SN-001-TEST',
        ];

        $this->postJson('/api/integrators/v1/equipments', $payload, $this->ctx['headers'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'NOVO EQUIPAMENTO']); // model uppercases name
    });

    it('returns 422 for invalid IP address', function () {
        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Equipamento',
            'ip'            => 'ip-invalido',
            'mac'           => 'AA:BB:CC:DD:EE:FF',
            'serial_number' => 'SN-002',
        ], $this->ctx['headers'])->assertUnprocessable();
    });

    it('returns 422 for invalid MAC address format', function () {
        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Equipamento',
            'ip'            => '10.0.0.1',
            'mac'           => 'mac-invalido',
            'serial_number' => 'SN-003',
        ], $this->ctx['headers'])->assertUnprocessable();
    });

    it('returns 422 when MAC is already registered for the same integrator', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'mac'           => 'AA:BB:CC:DD:EE:01',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Outro',
            'ip'            => '10.0.0.2',
            'mac'           => 'AA:BB:CC:DD:EE:01',
            'serial_number' => 'SN-UNIQUE',
        ], $this->ctx['headers'])->assertUnprocessable();
    });
});

describe('GET /api/integrators/v1/equipments/{id}', function () {
    beforeEach(function () {
        $this->ctx       = setupIntegrator();
        $this->equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);
    });

    it('shows equipment by UUID', function () {
        $this->getJson("/api/integrators/v1/equipments/{$this->equipment->id}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->equipment->id]);
    });

    it('shows equipment by code', function () {
        $this->getJson("/api/integrators/v1/equipments/{$this->equipment->code}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->equipment->id]);
    });

    it('shows equipment by integer number', function () {
        $numericPart = (int) substr($this->equipment->code, 4); // remove 'EIQ-'

        $this->getJson("/api/integrators/v1/equipments/{$numericPart}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->equipment->id]);
    });

    it('returns 404 for non-existent equipment', function () {
        $this->getJson('/api/integrators/v1/equipments/EIQ-NAOEXISTE', $this->ctx['headers'])
            ->assertNotFound();
    });
});

describe('PUT /api/integrators/v1/equipments/{id}', function () {
    beforeEach(function () {
        $this->ctx       = setupIntegrator();
        $this->equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);
    });

    it('updates equipment fields', function () {
        $this->putJson("/api/integrators/v1/equipments/{$this->equipment->id}", [
            'name'          => 'Nome Atualizado',
            'ip'            => '192.168.99.1',
            'mac'           => $this->equipment->mac,
            'serial_number' => $this->equipment->serial_number,
        ], $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'NOME ATUALIZADO']);
    });

    it('updates equipment by integer number', function () {
        $numericPart = (int) substr($this->equipment->code, 4); // remove 'EIQ-'

        $this->putJson("/api/integrators/v1/equipments/{$numericPart}", [
            'name'          => 'Atualizado Por Número',
            'ip'            => '10.0.0.99',
            'mac'           => $this->equipment->mac,
            'serial_number' => $this->equipment->serial_number,
        ], $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['name' => 'ATUALIZADO POR NÚMERO']);
    });
});

describe('DELETE /api/integrators/v1/equipments/{id}', function () {
    it('deletes equipment', function () {
        $ctx       = setupIntegrator();
        $equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $ctx['integrator']->id,
        ]);

        $this->deleteJson("/api/integrators/v1/equipments/{$equipment->id}", [], $ctx['headers'])
            ->assertNoContent();

        expect(EntityIntegratorEquipment::find($equipment->id))->toBeNull();
    });
});
