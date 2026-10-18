<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Entity, User};

/**
 * Vigência do contrato do convênio (contract_starts_at / contract_ends_at):
 * campos opcionais do cadastro de Configurações → Convênios, só datas.
 */
beforeEach(function () {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin  = User::factory()->create();
    $this->member = createEntityUser($this->entity, $this->admin, ClientRule::Admin->value);
});

function covenantContractPayload(array $overrides = []): array
{
    return array_merge([
        'name'  => 'Convenio Vigencia',
        'color' => '#112233',
        'table' => false,
    ], $overrides);
}

describe('Convênio — vigência do contrato', function () {
    it('persiste as datas ao criar, na clínica da sessão', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'contract_starts_at' => '2026-01-10',
                'contract_ends_at'   => '2026-12-31',
            ]))
            ->assertOk();

        $covenant = Covenant::query()->where('entity_id', $this->entity->id)->where('name', 'CONVENIO VIGENCIA')->firstOrFail();

        expect($covenant->contract_starts_at->toDateString())->toBe('2026-01-10')
            ->and($covenant->contract_ends_at->toDateString())->toBe('2026-12-31');
        $this->assertDatabaseHas('covenants', [
            'id'                 => $covenant->id,
            'entity_id'          => $this->entity->id,
            'contract_starts_at' => '2026-01-10',
            'contract_ends_at'   => '2026-12-31',
        ]);
    });

    it('cria sem datas (campos opcionais)', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload())
            ->assertOk();

        $covenant = Covenant::query()->where('entity_id', $this->entity->id)->firstOrFail();

        expect($covenant->contract_starts_at)->toBeNull()
            ->and($covenant->contract_ends_at)->toBeNull();
    });

    it('atualiza as datas e limpa quando o campo vai vazio', function () {
        $covenant = Covenant::factory()->create([
            'entity_id'          => $this->entity->id,
            'name'               => 'CONVENIO EDITAR',
            'color'              => '#445566',
            'contract_starts_at' => '2026-01-01',
            'contract_ends_at'   => '2026-06-30',
        ]);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->putJson(route('panel.setting.covenants.update', $covenant->id), [
                'name'               => 'CONVENIO EDITAR',
                'color'              => '#445566',
                'table'              => false,
                'active'             => true,
                'contract_starts_at' => '2026-02-01',
                'contract_ends_at'   => '2026-09-30',
            ])
            ->assertOk();

        $this->assertDatabaseHas('covenants', ['id' => $covenant->id, 'contract_starts_at' => '2026-02-01', 'contract_ends_at' => '2026-09-30']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->putJson(route('panel.setting.covenants.update', $covenant->id), [
                'name'               => 'CONVENIO EDITAR',
                'color'              => '#445566',
                'table'              => false,
                'active'             => true,
                'contract_starts_at' => '',
                'contract_ends_at'   => '',
            ])
            ->assertOk();

        $fresh = $covenant->fresh();

        expect($fresh->contract_starts_at)->toBeNull()
            ->and($fresh->contract_ends_at)->toBeNull();
    });

    it('recusa fim anterior ao início', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'contract_starts_at' => '2026-06-30',
                'contract_ends_at'   => '2026-06-29',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_ends_at');

        expect(Covenant::query()->where('entity_id', $this->entity->id)->count())->toBe(0);
    });

    it('aceita fim sem início e fim igual ao início', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'name'             => 'Somente Fim',
                'color'            => '#aa0000',
                'contract_ends_at' => '2026-12-31',
            ]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'name'               => 'Mesmo Dia',
                'color'              => '#00aa00',
                'contract_starts_at' => '2026-05-05',
                'contract_ends_at'   => '2026-05-05',
            ]))
            ->assertOk();

        expect(Covenant::query()->where('entity_id', $this->entity->id)->count())->toBe(2);
    });

    it('recusa data inválida', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'contract_starts_at' => 'não é data',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_starts_at');
    });

    it('show e edit devolvem a data em Y-m-d puro (contrato do input date)', function () {
        $covenant = Covenant::factory()->create([
            'entity_id'          => $this->entity->id,
            'name'               => 'CONVENIO DATAS',
            'color'              => '#778899',
            'contract_starts_at' => '2026-03-04',
            'contract_ends_at'   => '2026-11-05',
        ]);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->getJson(route('panel.setting.covenants.edit', $covenant->id))
            ->assertOk()
            ->assertJsonPath('data.contract_starts_at', '2026-03-04')
            ->assertJsonPath('data.contract_ends_at', '2026-11-05');

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->getJson(route('panel.setting.covenants.show', $covenant->id))
            ->assertOk()
            ->assertJsonPath('data.contract_starts_at', '2026-03-04')
            ->assertJsonPath('data.contract_ends_at', '2026-11-05');
    });

    it('edit devolve null quando não há vigência cadastrada', function () {
        $covenant = Covenant::factory()->create(['entity_id' => $this->entity->id, 'name' => 'SEM DATAS', 'color' => '#010203']);

        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->getJson(route('panel.setting.covenants.edit', $covenant->id))
            ->assertOk()
            ->assertJsonPath('data.contract_starts_at', null)
            ->assertJsonPath('data.contract_ends_at', null);
    });

    it('o index expõe os campos de data no formulário e os defaults do modal', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->get(route('panel.setting.covenants.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Panel/Settings/Catalog/Index')
                ->where('crudFields.contract_starts_at', null)
                ->where('crudFields.contract_ends_at', null)
                ->where('fields', fn ($fields) => collect($fields)->where('type', 'date')->pluck('key')->sort()->values()->all()
                    === ['contract_ends_at', 'contract_starts_at']));
    });

    it('não permite alterar a vigência de convênio de outra clínica nem global', function () {
        $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirs = Covenant::factory()->create(['entity_id' => $other->id, 'name' => 'ALHEIO', 'color' => '#abcdef', 'contract_ends_at' => '2026-01-01']);
        $global = Covenant::factory()->create(['entity_id' => null, 'name' => 'GLOBAL', 'color' => '#fedcba', 'contract_ends_at' => '2026-01-01']);

        foreach ([$theirs, $global] as $covenant) {
            $this->actingAs($this->admin)
                ->withSession(panelSession($this->member))
                ->putJson(route('panel.setting.covenants.update', $covenant->id), [
                    'name'             => $covenant->name,
                    'color'            => $covenant->color,
                    'table'            => false,
                    'active'           => true,
                    'contract_ends_at' => '2030-01-01',
                ]);

            expect($covenant->fresh()->contract_ends_at->toDateString())->toBe('2026-01-01');
        }
    });
});

describe('Convênio — vigência em update parcial (par efetivo)', function () {
    beforeEach(function () {
        // Gravado: 2026-06-01 → 2026-12-31.
        $this->covenant = Covenant::factory()->create([
            'entity_id'          => $this->entity->id,
            'name'               => 'CONVENIO PARCIAL',
            'color'              => '#314159',
            'contract_starts_at' => '2026-06-01',
            'contract_ends_at'   => '2026-12-31',
        ]);
    });

    // `type_method` é o marcador de update parcial do CovenantRequest (name/color/table deixam de ser obrigatórios).
    function covenantPartialPatch(array $payload)
    {
        return test()->actingAs(test()->admin)
            ->withSession(panelSession(test()->member))
            ->patchJson(route('panel.setting.covenants.update', test()->covenant->id), $payload + ['active' => true, 'type_method' => 'partial']);
    }

    it('só o fim, anterior ao início gravado → 422 em contract_ends_at e nada muda', function () {
        covenantPartialPatch(['contract_ends_at' => '2026-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_ends_at');

        $fresh = $this->covenant->fresh();

        expect($fresh->contract_starts_at->toDateString())->toBe('2026-06-01')
            ->and($fresh->contract_ends_at->toDateString())->toBe('2026-12-31');
    });

    it('só o início, depois do fim gravado → 422 em contract_starts_at e nada muda', function () {
        covenantPartialPatch(['contract_starts_at' => '2027-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_starts_at');

        $fresh = $this->covenant->fresh();

        expect($fresh->contract_starts_at->toDateString())->toBe('2026-06-01')
            ->and($fresh->contract_ends_at->toDateString())->toBe('2026-12-31');
    });

    it('só o fim válido (igual ou depois do início gravado) → 200 e grava', function () {
        covenantPartialPatch(['contract_ends_at' => '2026-06-01'])->assertOk();
        expect($this->covenant->fresh()->contract_ends_at->toDateString())->toBe('2026-06-01');

        covenantPartialPatch(['contract_ends_at' => '2027-05-05'])->assertOk();
        expect($this->covenant->fresh()->contract_ends_at->toDateString())->toBe('2027-05-05')
            ->and($this->covenant->fresh()->contract_starts_at->toDateString())->toBe('2026-06-01');
    });

    it('só o início válido (até o fim gravado) → 200 e grava', function () {
        covenantPartialPatch(['contract_starts_at' => '2026-12-31'])->assertOk();

        expect($this->covenant->fresh()->contract_starts_at->toDateString())->toBe('2026-12-31');
    });

    it('contract_starts_at null limpa o início e aceita qualquer fim', function () {
        covenantPartialPatch(['contract_starts_at' => null, 'contract_ends_at' => '2020-01-01'])->assertOk();

        $fresh = $this->covenant->fresh();

        expect($fresh->contract_starts_at)->toBeNull()
            ->and($fresh->contract_ends_at->toDateString())->toBe('2020-01-01');
    });

    it('limpar só o início (campo vazio) não compara com o fim gravado', function () {
        covenantPartialPatch(['contract_starts_at' => ''])->assertOk();

        expect($this->covenant->fresh()->contract_starts_at)->toBeNull();
    });

    it('com o início já limpo, qualquer fim enviado sozinho é aceito', function () {
        $this->covenant->update(['contract_starts_at' => null]);

        covenantPartialPatch(['contract_ends_at' => '2000-01-01'])->assertOk();

        expect($this->covenant->fresh()->contract_ends_at->toDateString())->toBe('2000-01-01');
    });

    it('contract_ends_at null limpa o fim e aceita qualquer início', function () {
        covenantPartialPatch(['contract_starts_at' => '2030-01-01', 'contract_ends_at' => null])->assertOk();

        $fresh = $this->covenant->fresh();

        expect($fresh->contract_starts_at->toDateString())->toBe('2030-01-01')
            ->and($fresh->contract_ends_at)->toBeNull();
    });

    it('update sem nenhuma das duas datas não valida nem altera a vigência', function () {
        covenantPartialPatch(['name' => 'CONVENIO PARCIAL'])->assertOk();

        $fresh = $this->covenant->fresh();

        expect($fresh->contract_starts_at->toDateString())->toBe('2026-06-01')
            ->and($fresh->contract_ends_at->toDateString())->toBe('2026-12-31');
    });

    it('par completo continua validado pela regra do campo (inalterado)', function () {
        covenantPartialPatch(['contract_starts_at' => '2026-06-30', 'contract_ends_at' => '2026-06-29'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_ends_at');
    });

    it('o create não é afetado: só o fim vale, sem comparar com nada', function () {
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->postJson(route('panel.setting.covenants.store'), covenantContractPayload([
                'name'             => 'NOVO SO FIM',
                'color'            => '#271828',
                'contract_ends_at' => '2000-01-01',
            ]))
            ->assertOk();
    });

    it('convênio de outra clínica não vira referência da comparação', function () {
        $other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $theirs = Covenant::factory()->create([
            'entity_id'          => $other->id,
            'name'               => 'ALHEIO PARCIAL',
            'color'              => '#161803',
            'contract_starts_at' => '2026-06-01',
            'contract_ends_at'   => '2026-12-31',
        ]);

        // 404 do controller (não é da clínica); a request não vaza 422 por dado alheio.
        $this->actingAs($this->admin)
            ->withSession(panelSession($this->member))
            ->patchJson(route('panel.setting.covenants.update', $theirs->id), ['active' => true, 'type_method' => 'partial', 'contract_ends_at' => '2026-01-01'])
            ->assertNotFound();

        expect($theirs->fresh()->contract_ends_at->toDateString())->toBe('2026-12-31');
    });
});
