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

    // ip/mac/serial_number são opcionais desde a mudança "Equipamento do
    // integrador: IP, MAC e serial agora são opcionais" — só o nome é
    // obrigatório (muitos aparelhos reais não têm rede própria).
    it('creates equipment with only the required name field', function () {
        $response = $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Equipamento Sem Rede',
        ], $this->ctx['headers'])->assertCreated();

        expect($response->json('data.attributes.name'))->toBe('EQUIPAMENTO SEM REDE')
            ->and($response->json('data.attributes.ip'))->toBeNull()
            ->and($response->json('data.attributes.mac'))->toBeNull()
            ->and($response->json('data.attributes.serial_number'))->toBeNull()
            ->and($response->json('data.attributes.active'))->toBeTrue();
    });

    it('creates equipment with only ip informed, leaving mac and serial_number null', function () {
        $response = $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Equipamento Só IP',
            'ip'   => '10.0.0.77',
        ], $this->ctx['headers'])->assertCreated();

        expect($response->json('data.attributes.ip'))->toBe('10.0.0.77')
            ->and($response->json('data.attributes.mac'))->toBeNull()
            ->and($response->json('data.attributes.serial_number'))->toBeNull();
    });

    it('returns 422 when name is already registered for the same integrator', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'Retinografo Compartilhado',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Retinografo Compartilhado',
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('returns 422 when serial_number is already registered for the same integrator', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'serial_number' => 'SN-DUP-001',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Outro Equipamento',
            'serial_number' => 'SN-DUP-001',
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('serial_number');
    });

    it('allows the same equipment name to be used by a different integrator', function () {
        $other = setupIntegrator();
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
            'name'          => 'Nome Compartilhado',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Nome Compartilhado',
        ], $this->ctx['headers'])->assertCreated();
    });

    // Não é bug: a coluna 'mac' é macaddr nativo do Postgres (ver migration),
    // que normaliza/compara endereços independente de caixa — cobre a
    // regressão caso a coluna algum dia vire string simples.
    it('rejects a mac that only differs by letter case from an existing registration', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'mac'           => 'AA:BB:CC:DD:EE:20',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Duplicata Por Caixa Mac',
            'mac'  => 'aa:bb:cc:dd:ee:20',
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('mac');
    });

    // BUGFIX (achado nesta rodada de testes): EntityIntegratorEquipmentRequest::uniqueRule()
    // comparava o valor BRUTO do request contra a coluna (varchar comum,
    // diferente de mac/ip que têm tipo nativo do Postgres), mas o model
    // (EntityIntegratorEquipment::UPPERCASE_FIELDS) uppercasa name/serial_number
    // ao salvar. 'retinografo x' != 'RETINOGRAFO X' numa comparação SQL exata,
    // então um nome/serial reenviado em outra caixa escapava da checagem de
    // unicidade mesmo virando o MESMO valor após a normalização — permitindo
    // duplicata funcional (dois registros com o mesmo nome/serial pós-uppercase).
    // Fix: EntityIntegratorEquipmentRequest::prepareForValidation() agora
    // uppercasa esses campos ANTES da validação de unicidade.
    it('rejects a name that only differs by letter case from an existing registration', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'Retinografo Caixa Baixa',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'retinografo caixa baixa',
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('rejects a serial_number that only differs by letter case from an existing registration', function () {
        EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'serial_number' => 'SN-CASE-001',
        ]);

        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Outro Equipamento Caixa',
            'serial_number' => 'sn-case-001',
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('serial_number');
    });

    it('returns 401 without authentication', function () {
        $this->postJson('/api/integrators/v1/equipments', ['name' => 'Sem Auth'])
            ->assertUnauthorized();
    });
});

describe('POST /api/integrators/v1/equipments — hardware-identity restore (soft-delete)', function () {
    beforeEach(function () {
        $this->ctx = setupIntegrator();
    });

    it('restores a soft-deleted equipment matched by mac instead of creating a duplicate', function () {
        $original = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'name'          => 'Retinografo Antigo',
            'mac'           => 'AA:BB:CC:DD:EE:10',
        ]);
        $originalId   = $original->id;
        $originalCode = $original->code;
        $original->delete();

        // 200, não 201: é o MESMO registro (restaurado + atualizado), não uma
        // inserção nova — Laravel só retorna 201 quando o Model do resource
        // tem wasRecentlyCreated=true, o que não é o caso de um find+restore.
        $response = $this->postJson('/api/integrators/v1/equipments', [
            'name' => 'Retinografo Recadastrado',
            'mac'  => 'AA:BB:CC:DD:EE:10',
        ], $this->ctx['headers'])->assertOk();

        expect($response->json('data.id'))->toBe($originalId)
            ->and($response->json('data.attributes.code'))->toBe($originalCode)
            ->and($response->json('data.attributes.name'))->toBe('RETINOGRAFO RECADASTRADO');

        expect(EntityIntegratorEquipment::withTrashed()
            ->where('integrator_id', $this->ctx['integrator']->id)
            ->count())->toBe(1);
        expect(EntityIntegratorEquipment::find($originalId)?->trashed())->toBeFalse();
    });

    // BUGFIX (achado nesta rodada de testes): findOrCreate() só chamava
    // restore() no branch de match por identidade de hardware, mas aplicava
    // 'active' => $request->boolean('active') sem o mesmo guard usado por
    // update() (if ($request->has('active'))). Como EntityIntegratorEquipmentRequest
    // não inclui 'active' no seu contrato, um recadastro (POST) do mesmo
    // equipamento após exclusão lógica reativava o deleted_at (restore) mas
    // marcava active=false — o cliente via o equipamento "restaurado" porém
    // inativo, sem nunca ter pedido isso. Fix: só sobrescrever 'active' quando
    // o request o envia explicitamente; ao restaurar sem 'active' explícito,
    // o equipamento volta como active=true (mesma semântica de uma criação nova).
    it('reactivates a soft-deleted equipment on restore even when active is omitted from the payload', function () {
        $original = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'serial_number' => 'SN-RESTORE-001',
            'active'        => true,
        ]);
        $original->delete();

        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Equipamento Restaurado',
            'serial_number' => 'SN-RESTORE-001',
        ], $this->ctx['headers'])->assertOk()
            ->assertJsonPath('data.attributes.active', true);

        expect(EntityIntegratorEquipment::find($original->id)->active)->toBeTrue();
    });

    it('honors an explicit active=false when restoring a soft-deleted equipment', function () {
        $original = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'serial_number' => 'SN-RESTORE-002',
            'active'        => true,
        ]);
        $original->delete();

        $this->postJson('/api/integrators/v1/equipments', [
            'name'          => 'Equipamento Restaurado Inativo',
            'serial_number' => 'SN-RESTORE-002',
            'active'        => false,
        ], $this->ctx['headers'])->assertOk()
            ->assertJsonPath('data.attributes.active', false);
    });

    it('does not list soft-deleted equipment in the index', function () {
        $equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);
        $equipment->delete();

        $this->getJson('/api/integrators/v1/equipments', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    });

    it('still shows a soft-deleted equipment directly by id', function () {
        $equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);
        $equipment->delete();

        $this->getJson("/api/integrators/v1/equipments/{$equipment->id}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $equipment->id]);
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

    it('returns 404 for equipment belonging to another integrator', function () {
        $other          = setupIntegrator();
        $otherEquipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
        ]);

        $this->getJson("/api/integrators/v1/equipments/{$otherEquipment->id}", $this->ctx['headers'])
            ->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->getJson("/api/integrators/v1/equipments/{$this->equipment->id}")
            ->assertUnauthorized();
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

    // Partial update: campos omitidos não podem ser nulados. EntityIntegratorEquipmentService::update()
    // usa $request->only(FILLABLE_FIELDS) + array_filter(!== null), então uma
    // chave ausente do body simplesmente não entra no update().
    it('preserves ip, mac and serial_number when the update payload only sends name', function () {
        $this->putJson("/api/integrators/v1/equipments/{$this->equipment->id}", [
            'name' => 'Somente Nome Mudou',
        ], $this->ctx['headers'])->assertOk();

        $fresh = $this->equipment->fresh();

        expect($fresh->name)->toBe('SOMENTE NOME MUDOU')
            ->and($fresh->ip)->toBe($this->equipment->ip)
            ->and($fresh->mac)->toBe($this->equipment->mac)
            ->and($fresh->serial_number)->toBe($this->equipment->serial_number);
    });

    it('returns 422 when updating to a mac already used by another equipment of the same integrator', function () {
        $other = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
            'mac'           => 'AA:BB:CC:DD:EE:30',
        ]);

        $this->putJson("/api/integrators/v1/equipments/{$this->equipment->id}", [
            'name' => $this->equipment->name,
            'mac'  => $other->mac,
        ], $this->ctx['headers'])->assertUnprocessable()
            ->assertJsonValidationErrors('mac');
    });

    it('returns 404 when updating equipment belonging to another integrator', function () {
        $other          = setupIntegrator();
        $otherEquipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
        ]);

        $this->putJson("/api/integrators/v1/equipments/{$otherEquipment->id}", [
            'name' => 'Tentativa De Outro Integrador',
        ], $this->ctx['headers'])->assertNotFound();
    });

    it('returns 401 without authentication', function () {
        $this->putJson("/api/integrators/v1/equipments/{$this->equipment->id}", ['name' => 'X'])
            ->assertUnauthorized();
    });
});

describe('DELETE /api/integrators/v1/equipments/{id}', function () {
    beforeEach(function () {
        $this->ctx       = setupIntegrator();
        $this->equipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $this->ctx['integrator']->id,
        ]);
    });

    it('deletes equipment', function () {
        $this->deleteJson("/api/integrators/v1/equipments/{$this->equipment->id}", [], $this->ctx['headers'])
            ->assertNoContent();

        expect(EntityIntegratorEquipment::find($this->equipment->id))->toBeNull();
    });

    it('is idempotent when deleting an already soft-deleted equipment', function () {
        $this->equipment->delete();

        $this->deleteJson("/api/integrators/v1/equipments/{$this->equipment->id}", [], $this->ctx['headers'])
            ->assertNoContent();
    });

    it('returns 404 when deleting equipment belonging to another integrator', function () {
        $other          = setupIntegrator();
        $otherEquipment = EntityIntegratorEquipment::factory()->create([
            'integrator_id' => $other['integrator']->id,
        ]);

        $this->deleteJson("/api/integrators/v1/equipments/{$otherEquipment->id}", [], $this->ctx['headers'])
            ->assertNotFound();

        expect(EntityIntegratorEquipment::find($otherEquipment->id))->not->toBeNull();
    });

    it('returns 401 without authentication', function () {
        $this->deleteJson("/api/integrators/v1/equipments/{$this->equipment->id}")
            ->assertUnauthorized();
    });
});
