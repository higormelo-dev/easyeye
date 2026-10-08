<?php

declare(strict_types=1);

use App\Enums\{ClientRule, SaasRule};
use App\Models\{AuditLog, Cid10Code, Covenant, Entity, EntityUser, ExamKit, Indication, MedicalRecord, MedicalRecordDocumentation, Patient, People, Procedure, Schedule, User};
use App\Services\Documents\ExamKitService;
use Database\Seeders\{ExamKitsSeeder, ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Inertia\Testing\AssertableInertia;

/*
 * Fase 2 / B2 — kits de exames por convênio.
 *  - kits GLOBAIS (padrão do EasyEye, ExamKitsSeeder + manager) x da CLÍNICA
 *    (Configurações › Kits: CRUD, cópia do padrão, convênios — vazio =
 *    todos; só ativos);
 *  - no pedido: kits filtrados pelo convênio do ATENDIMENTO (ou do paciente),
 *    item resolvido pelo código TUSS no catálogo da clínica; sem cadastro,
 *    texto com aviso;
 *  - pedido estruturado: nome/código do catálogo vêm do servidor, teto de 20
 *    exames, indicações/CIDs/kits validados por clínica.
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class, ExamKitsSeeder::class]);

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->adminEu = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);
    $this->docEu   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor  = doctorRecordForEntityUser($this->docEu);

    $this->unimed = Covenant::factory()->create(['name' => 'UNIMED TESTE']);
    $this->amil   = Covenant::factory()->create(['name' => 'AMIL TESTE']);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'ANA PACIENTE'])->id,
        'covenant_id' => $this->unimed->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Glaucoma',
    ]);

    $this->other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEu = createEntityUser($this->other, User::factory()->create(), ClientRule::Admin->value);
});

function fb2As(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function fb2KitPayload(array $overrides = []): array
{
    return array_merge([
        'name'   => 'Glaucoma UNIMED',
        'active' => true,
        'items'  => [
            ['name' => 'Campimetria computadorizada', 'tuss_code' => '40103137'],
            ['name' => 'OCT de nervo óptico', 'tuss_code' => '41501144'],
        ],
    ], $overrides);
}

function fb2Kits(object $test): array
{
    return fb2As($test, $test->docEu)
        ->getJson(route('panel.patients.medicalrecords.exam-kits', [$test->patient, $test->record]))
        ->assertOk()
        ->json();
}

function fb2Request(object $test, array $payload)
{
    return fb2As($test, $test->docEu)->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [$test->patient, $test->record, 'procedure-request']),
        $payload,
    );
}

describe('kits padrão (globais)', function () {
    it('o seeder cria os 5 kits padrão com os códigos TUSS conferidos e é idempotente', function () {
        (new ExamKitsSeeder())->run();

        $kits = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->with('items')->orderBy('sort_order')->get();

        expect($kits->pluck('slug')->all())->toBe(['glaucoma', 'pre_op_catarata', 'retina_diabetica', 'ceratocone', 'refrativa'])
            ->and($kits->firstWhere('slug', 'glaucoma')->items->pluck('tuss_code')->all())->toBe(['40103137', '41501144', '41501128', '41301242', '41301315'])
            ->and($kits->firstWhere('slug', 'pre_op_catarata')->items->last()->tuss_code)->toBeNull()
            ->and($kits->firstWhere('slug', 'refrativa')->items->whereNull('tuss_code')->pluck('name')->all())->toBe(['Aberrometria', 'Pupilometria']);
    });

    it('seeder não sobrescreve um kit padrão ajustado no manager', function () {
        $kit = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->where('slug', 'glaucoma')->firstOrFail();
        $kit->update(['name' => 'Glaucoma (ajustado)']);

        (new ExamKitsSeeder())->run();

        expect($kit->fresh()->name)->toBe('Glaucoma (ajustado)');
    });
});

describe('Configurações › Kits de exames (clínica)', function () {
    it('lista kits da clínica + padrão; só convênios ativos no seletor', function () {
        $this->amil->update(['active' => false]);

        fb2As($this, $this->adminEu)->get(route('panel.setting.exam-kits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Panel/Settings/ExamKits/Index')
                ->has('globalKits', 5)
                ->has('clinicKits', 0)
                ->where('covenants', fn ($list) => collect($list)->pluck('id')->contains($this->unimed->id)
                    && ! collect($list)->pluck('id')->contains($this->amil->id)));
    });

    it('cria kit vinculado a convênio ativo; convênio inativo ou de outra clínica → 422', function () {
        $res = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['covenant_ids' => [$this->unimed->id]]))
            ->assertCreated();

        expect($res->json('data.covenants.0.id'))->toBe($this->unimed->id)
            ->and(ExamKit::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1);

        $this->amil->update(['active' => false]);
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Outro', 'covenant_ids' => [$this->amil->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('covenant_ids.0');

        $foreign = Covenant::factory()->create(['entity_id' => $this->other->id]);
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Outro 2', 'covenant_ids' => [$foreign->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('covenant_ids.0');
    });

    it('convênio que ficou inativo depois continua no kit ao editar outro campo', function () {
        $id = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['covenant_ids' => [$this->unimed->id]]))->json('data.id');
        $this->unimed->update(['active' => false]);

        fb2As($this, $this->adminEu)->putJson(route('panel.setting.exam-kits.update', $id), fb2KitPayload(['name' => 'Renomeado', 'covenant_ids' => [$this->unimed->id]]))
            ->assertOk();
    });

    it('nome único na clínica, ao menos 1 exame e no máximo 20; procedimento de outra clínica → 422', function () {
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload())->assertCreated();
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'glaucoma unimed']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Vazio', 'items' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $many = array_map(fn ($i) => ['name' => "Exame {$i}"], range(1, ExamKitService::MAX_ITEMS + 1));
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Grande', 'items' => $many]))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $foreignProc = Procedure::query()->create(['entity_id' => $this->other->id, 'code' => '99999999', 'name' => 'EXAME DA OUTRA', 'active' => true]);
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Com proc', 'items' => [['name' => 'Exame X', 'procedure_id' => $foreignProc->id]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.procedure_id');
    });

    it('a ordem dos exames do kit é a da tela (com e sem item do catálogo)', function () {
        $proc = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => '41301250', 'name' => 'MAPEAMENTO DE RETINA', 'active' => true]);

        $id = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['items' => [
            ['name' => 'Retinografia', 'tuss_code' => '41301315'],
            ['name' => 'Mapeamento de retina', 'tuss_code' => '41301250', 'procedure_id' => $proc->id],
            ['name' => 'OCT de mácula', 'tuss_code' => '41501144'],
        ]]))->assertCreated()->json('data.id');

        expect(ExamKit::query()->withoutGlobalScopes()->findOrFail($id)->items()->pluck('name')->all())
            ->toBe(['Retinografia', 'Mapeamento de retina', 'OCT de mácula']);
    });

    it('item vinculado com o código trocado perde o vínculo e vale pelo código digitado; código em branco recebe o do catálogo (2ª revisão, P2-D)', function () {
        $mapa = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => '41301250', 'name' => 'MAPEAMENTO DE RETINA', 'active' => true]);
        $oct  = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => '41501144', 'name' => 'OCT (CLÍNICA)', 'active' => true]);

        $id = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['items' => [
            // Escolhido no catálogo e depois com o código editado para o do OCT.
            ['name' => 'Mapeamento de retina', 'tuss_code' => '41501144', 'procedure_id' => $mapa->id],
            // Vinculado sem código: recebe o do catálogo.
            ['name' => 'Mapeamento de retina', 'tuss_code' => null, 'procedure_id' => $mapa->id],
            // Vinculado e coerente: fica.
            ['name' => 'Mapeamento', 'tuss_code' => '41301250', 'procedure_id' => $mapa->id],
        ]]))->assertCreated()->json('data.id');

        $items = ExamKit::query()->withoutGlobalScopes()->findOrFail($id)->items()->orderBy('sort_order')->get(['tuss_code', 'procedure_id']);
        expect($items->map(fn ($i) => [$i->tuss_code, $i->procedure_id])->all())->toBe([
            ['41501144', null],
            ['41301250', $mapa->id],
            ['41301250', $mapa->id],
        ]);

        // No pedido, o 1º item sai como o exame do CÓDIGO editado (não o antigo).
        $kit = collect(fb2Kits($this)['kits'])->firstWhere('name', 'Glaucoma UNIMED');
        expect($kit['items'][0])->toMatchArray(['procedure_id' => $oct->id, 'code' => '41501144', 'name' => 'OCT (CLÍNICA)']);
    });

    it('copia um kit padrão para a clínica (sem convênio = todos) e o padrão some do pedido', function () {
        $global = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->where('slug', 'ceratocone')->firstOrFail();

        $res = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.copy', $global->id))->assertCreated();

        $copy = ExamKit::query()->withoutGlobalScopes()->findOrFail($res->json('data.id'));
        expect($copy->entity_id)->toBe($this->entity->id)
            ->and($copy->source_kit_id)->toBe($global->id)
            ->and($copy->items()->pluck('tuss_code')->all())->toBe(['41301080', '41501128', '41301269']);

        $kits = collect(fb2Kits($this)['kits']);
        expect($kits->where('name', 'Ceratocone')->pluck('origin')->all())->toBe(['clinic']);
    });

    it('kit de outra clínica: não aparece, não edita, não exclui (404)', function () {
        $id = fb2As($this, $this->otherEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Kit da outra']))->assertCreated()->json('data.id');

        fb2As($this, $this->adminEu)->putJson(route('panel.setting.exam-kits.update', $id), fb2KitPayload())->assertNotFound();
        fb2As($this, $this->adminEu)->deleteJson(route('panel.setting.exam-kits.destroy', $id))->assertNotFound();

        expect(collect(fb2Kits($this)['kits'])->pluck('name'))->not->toContain('Kit da outra');
    });

    it('médico sem permissão de configurações não acessa a tela', function () {
        fb2As($this, $this->docEu)->get(route('panel.setting.exam-kits.index'))->assertForbidden();
        fb2As($this, $this->docEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload())->assertForbidden();
    });

    it('exclui kit da clínica (pedidos já emitidos não mudam)', function () {
        $id = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload())->json('data.id');

        fb2As($this, $this->adminEu)->deleteJson(route('panel.setting.exam-kits.destroy', $id))->assertOk();

        expect(ExamKit::query()->find($id))->toBeNull()
            ->and(ExamKit::query()->withTrashed()->find($id)?->deleted_at)->not->toBeNull();
    });
});

describe('kits no pedido de exames (convênio)', function () {
    it('filtra pelo convênio do ATENDIMENTO; sem agendamento, pelo do paciente; vazio = todos', function () {
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Só UNIMED', 'covenant_ids' => [$this->unimed->id]]))->assertCreated();
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Só AMIL', 'covenant_ids' => [$this->amil->id]]))->assertCreated();
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Todos']))->assertCreated();

        $data = fb2Kits($this);
        expect($data['covenant']['id'])->toBe($this->unimed->id)
            ->and(collect($data['kits'])->where('origin', 'clinic')->pluck('name')->all())->toBe(['Só UNIMED', 'Todos'])
            ->and(collect($data['kits'])->where('origin', 'global'))->toHaveCount(5);

        // Atendimento pelo AMIL (agendamento do prontuário) vence o cadastro.
        $schedule = Schedule::create([
            'entity_id'   => $this->entity->id, 'doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'covenant_id' => $this->amil->id, 'full_name' => 'ANA PACIENTE', 'date_time' => now(), 'situation' => 1, 'active' => true,
        ]);
        $this->record->update(['schedule_id' => $schedule->id]);

        $data = fb2Kits($this);
        expect($data['covenant']['id'])->toBe($this->amil->id)
            ->and(collect($data['kits'])->where('origin', 'clinic')->pluck('name')->all())->toBe(['Só AMIL', 'Todos']);
    });

    it('kit inativo não aparece no pedido', function () {
        fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload(['name' => 'Desligado', 'active' => false]))->assertCreated();

        expect(collect(fb2Kits($this)['kits'])->pluck('name'))->not->toContain('Desligado');
    });

    it('item resolvido pelo código TUSS no catálogo da clínica; sem cadastro vira texto com aviso', function () {
        $proc = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => '40103137', 'name' => 'CAMPIMETRIA (CLÍNICA)', 'active' => true]);

        $glaucoma = collect(fb2Kits($this)['kits'])->firstWhere('name', 'Glaucoma');

        expect($glaucoma['items'][0])->toBe(['procedure_id' => $proc->id, 'code' => '40103137', 'name' => 'CAMPIMETRIA (CLÍNICA)', 'in_catalog' => true])
            ->and($glaucoma['items'][1])->toMatchArray(['procedure_id' => null, 'code' => '41501144', 'in_catalog' => false]);
    });

    it('procedimento de OUTRA clínica com o mesmo código não resolve o item', function () {
        Procedure::query()->create(['entity_id' => $this->other->id, 'code' => '40103137', 'name' => 'CAMPIMETRIA DA OUTRA', 'active' => true]);

        $item = collect(fb2Kits($this)['kits'])->firstWhere('name', 'Glaucoma')['items'][0];

        expect($item)->toMatchArray(['procedure_id' => null, 'in_catalog' => false, 'name' => 'Campimetria computadorizada']);
    });

    it('prontuário de outra clínica → 404', function () {
        fb2As($this, $this->otherEu)
            ->getJson(route('panel.patients.medicalrecords.exam-kits', [$this->patient, $this->record]))
            ->assertNotFound();
    });
});

describe('pedido de exames estruturado', function () {
    beforeEach(function () {
        $this->proc = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => '40103137', 'name' => 'CAMPIMETRIA COMPUTADORIZADA', 'active' => true]);
        $this->ind  = Indication::query()->create(['entity_id' => null, 'description' => 'Suspeita de glaucoma', 'active' => true]);
        Cid10Code::query()->firstOrCreate(['code' => 'H40.1'], ['description' => 'Glaucoma primário de ângulo aberto', 'source' => Cid10Code::SOURCE_DATASUS]);
        $this->kit = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->where('slug', 'glaucoma')->firstOrFail();
    });

    it('monta o pedido no servidor: nome do catálogo (não o da tela), TUSS, caráter, indicação, CID e observações', function () {
        fb2Request($this, [
            'items' => [
                ['procedure_id' => $this->proc->id, 'name' => 'NOME FORJADO', 'kit_id' => $this->kit->id],
                ['name' => 'Tomografia de coerência óptica (OCT) de nervo óptico', 'code' => '41501144', 'kit_id' => $this->kit->id],
                ['name' => 'Exame avulso sem código'],
            ],
            'type'            => 'rotina',
            'indications'     => [$this->ind->id],
            'indication_text' => 'PIO 26 mmHg',
            'cids'            => ['h40.1'],
            'notes'           => 'Ambos os olhos',
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('<li>CAMPIMETRIA COMPUTADORIZADA — cód. 40103137</li>')
            ->and($doc->content)->not->toContain('NOME FORJADO')
            ->and($doc->content)->toContain('OCT) de nervo óptico — cód. 41501144')
            ->and($doc->content)->toContain('<li>Exame avulso sem código</li>')
            ->and($doc->content)->toContain('<strong>Caráter:</strong> Rotina')
            ->and($doc->content)->toContain('Suspeita de glaucoma; PIO 26 mmHg')
            ->and($doc->content)->toContain('H40.1 — Glaucoma primário de ângulo aberto')
            ->and($doc->content)->toContain('Ambos os olhos')
            ->and($doc->metadata['items'][0])->toMatchArray(['procedure_id' => $this->proc->id, 'in_catalog' => true, 'kit_id' => $this->kit->id])
            ->and($doc->metadata['items'][2])->toMatchArray(['procedure_id' => null, 'code' => null, 'in_catalog' => false])
            ->and($doc->metadata['kits'])->toBe([['id' => $this->kit->id, 'name' => 'Glaucoma']])
            ->and($doc->metadata['cids'])->toBe([['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']]);
    });

    it('pedido em TEXTO LIVRE (sem item nenhum) também leva caráter, indicação, CID e observações (16ª revisão Codex, P2)', function () {
        // Sem item (texto digitado direto, ou modelo pessoal só-de-corpo):
        // esses campos eram descartados em silêncio — só o `content` chegava
        // no documento, metadados ficavam vazios.
        fb2Request($this, [
            'content'         => 'Avaliação de rotina conforme evolução clínica.',
            'type'            => 'rotina',
            'indications'     => [$this->ind->id],
            'indication_text' => 'PIO 26 mmHg',
            'cids'            => ['h40.1'],
            'notes'           => 'Ambos os olhos',
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect($doc->content)->toContain('Avaliação de rotina conforme evolução clínica.')
            ->and($doc->content)->toContain('<strong>Caráter:</strong> Rotina')
            ->and($doc->content)->toContain('Suspeita de glaucoma; PIO 26 mmHg')
            ->and($doc->content)->toContain('H40.1 — Glaucoma primário de ângulo aberto')
            ->and($doc->content)->toContain('Ambos os olhos')
            ->and($doc->metadata['items'])->toBe([])
            ->and($doc->metadata['type'])->toBe('rotina')
            ->and($doc->metadata['indication_text'])->toBe('PIO 26 mmHg')
            ->and($doc->metadata['cids'])->toBe([['code' => 'H40.1', 'description' => 'Glaucoma primário de ângulo aberto']])
            ->and($doc->metadata['notes'])->toBe('Ambos os olhos');
    });

    it('a ordem do médico vale (itens do catálogo e avulsos misturados)', function () {
        fb2Request($this, ['items' => [
            ['name' => 'Retinografia', 'code' => '41301315'],
            ['procedure_id' => $this->proc->id],
            ['name'         => 'Aberrometria'],
        ]])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->firstOrFail();

        expect(array_column($doc->metadata['items'], 'name'))->toBe(['Retinografia', 'CAMPIMETRIA COMPUTADORIZADA', 'Aberrometria'])
            ->and(strpos($doc->content, 'Retinografia'))->toBeLessThan(strpos($doc->content, 'CAMPIMETRIA'));
    });

    it('mesmo exame em dois kits sai uma vez', function () {
        fb2Request($this, ['items' => [
            ['procedure_id' => $this->proc->id], ['procedure_id' => $this->proc->id], ['name' => 'Retinografia', 'code' => '41301315'], ['name' => 'Retinografia', 'code' => '41301315'],
        ]])->assertCreated();

        expect(substr_count(MedicalRecordDocumentation::query()->value('content'), '<li>'))->toBe(2);
    });

    it('teto de 20 exames por pedido (antes 10 itens somados)', function () {
        $twenty = array_map(fn ($i) => ['name' => "Exame {$i}"], range(1, 20));
        fb2Request($this, ['items' => $twenty])->assertCreated();

        fb2Request($this, ['items' => [...$twenty, ['name' => 'Exame 21']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    });

    it('procedimento, indicação e kit de OUTRA clínica → 422; CID fora do catálogo → 422', function () {
        $foreignProc = Procedure::query()->create(['entity_id' => $this->other->id, 'code' => '1', 'name' => 'X', 'active' => true]);
        $foreignInd  = Indication::query()->create(['entity_id' => $this->other->id, 'description' => 'Y', 'active' => true]);
        $foreignKit  = ExamKit::query()->withoutGlobalScopes()->create(['entity_id' => $this->other->id, 'name' => 'Kit outra', 'active' => true]);

        fb2Request($this, ['items' => [['procedure_id' => $foreignProc->id]]])->assertUnprocessable()->assertJsonValidationErrors('items.0.procedure_id');
        fb2Request($this, ['items' => [['name' => 'A']], 'indications' => [$foreignInd->id]])->assertUnprocessable()->assertJsonValidationErrors('indications.0');
        fb2Request($this, ['items' => [['name' => 'A']], 'kit_ids' => [$foreignKit->id]])->assertUnprocessable()->assertJsonValidationErrors('kit_ids.0');
        fb2Request($this, ['items' => [['name' => 'A']], 'cids' => ['U99.9']])->assertUnprocessable()->assertJsonValidationErrors('cids.0');

        expect(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('sem itens continua aceitando o texto livre antigo', function () {
        fb2Request($this, ['content' => "- Mapeamento de retina\n- Retinografia"])->assertCreated();

        expect(MedicalRecordDocumentation::query()->value('content'))->toContain('- Mapeamento de retina<br />');

        fb2Request($this, [])->assertUnprocessable()->assertJsonValidationErrors('content');
    });
});

describe('manager — kits padrão', function () {
    beforeEach(function () {
        $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->admin = User::factory()->create();
        createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);
        $this->asManager = fn (string $rule = 'admin') => $this->actingAs($this->admin)->withSession([
            'selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule,
        ]);
    });

    it('admin SaaS cria kit GLOBAL (entity_id nulo, mesmo com a sessão na entity do SaaS)', function () {
        ($this->asManager)()->get(route('manager.exam-kits.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Panel/Manager/ExamKits/Index')->has('kits', 5));

        $id = ($this->asManager)()->postJson(route('manager.exam-kits.store'), fb2KitPayload(['name' => 'Uveíte']))->assertCreated()->json('data.id');

        expect(ExamKit::query()->withoutGlobalScopes()->findOrFail($id)->entity_id)->toBeNull();
    });

    it('excluir exige justificativa e fica na trilha; kit de clínica não é editável pelo manager', function () {
        $kit = ExamKit::query()->withoutGlobalScopes()->whereNull('entity_id')->where('slug', 'refrativa')->firstOrFail();

        ($this->asManager)()->deleteJson(route('manager.exam-kits.destroy', $kit->id))->assertUnprocessable()->assertJsonValidationErrors('reason');
        ($this->asManager)()->deleteJson(route('manager.exam-kits.destroy', $kit->id), ['reason' => 'Kit substituído por versão revisada pelo conselho'])->assertOk();

        expect(ExamKit::query()->withoutGlobalScopes()->whereNull('deleted_at')->find($kit->id))->toBeNull()
            ->and(AuditLog::query()->where('auditable_id', $kit->id)->where('new_values->name', 'Cirurgia refrativa')->exists())->toBeTrue();

        $clinicKit = fb2As($this, $this->adminEu)->postJson(route('panel.setting.exam-kits.store'), fb2KitPayload())->json('data.id');
        ($this->asManager)()->putJson(route('manager.exam-kits.update', $clinicKit), fb2KitPayload())->assertNotFound();
    });

    it('papel SaaS não-admin não acessa', function () {
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);

        $this->actingAs($support)->withSession([
            'selected_entity_id' => $this->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => SaasRule::Support->value,
        ])->get(route('manager.exam-kits.index'))->assertForbidden();
    });
});
