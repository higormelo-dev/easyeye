<?php

use App\Enums\{CovenantSource, ImportStatus, SaasRule};
use App\Jobs\ProcessCovenantImportJob;
use App\Models\{Covenant, CovenantImport, Entity, Patient, User};
use App\Models\CovenantPlan;
use App\Support\PanelNavigation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Route, Storage};
use Inertia\Testing\AssertableInertia;

/**
 * Manager → Convênios: catálogo GLOBAL de convênios (operadoras da ANS +
 * manuais), admin SaaS only.
 */
function covenantsManagerSession(Entity $saas, string $rule = 'admin'): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ];
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

function asCovenantsAdmin()
{
    return test()->actingAs(test()->admin)->withSession(covenantsManagerSession(test()->saas));
}

function covenantRows(array $query = []): array
{
    return asCovenantsAdmin()->get(route('manager.covenants.index', $query))
        ->assertOk()
        ->viewData('page')['props']['covenants']['data'];
}

function ansCovenant(array $attributes = []): Covenant
{
    return Covenant::factory()->create([
        'name'         => 'OPERADORA X',
        'ans_registry' => '123456',
        'company_name' => 'Operadora X Ltda',
        'ans_modality' => 'Medicina de Grupo',
        'source'       => CovenantSource::Ans,
        'active'       => true,
        ...$attributes,
    ]);
}

function particularCovenant(): Covenant
{
    // Semeado por migration (2026_09_25_100000_seed_global_particular_covenant).
    return Covenant::withoutGlobalScopes()->whereNull('entity_id')->where('name', 'PARTICULAR')->sole();
}

it('lista só o catálogo global (nunca convênio de clínica)', function () {
    Covenant::factory()->create(['name' => 'GLOBAL X']);
    Covenant::factory()->create(['entity_id' => $this->clinic->id, 'name' => 'DA CLINICA']);

    asCovenantsAdmin()->get(route('manager.covenants.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Panel/Manager/Covenants/Index')
            ->where('covenants.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['GLOBAL X', 'PARTICULAR'])
            ->where('covenants.data', fn ($rows) => collect($rows)->firstWhere('name', 'PARTICULAR')['is_particular'] === true)
            ->has('modalities', 8)
            ->has('ufs', 27));
});

it('filtra por origem, modalidade, UF e canceladas; busca por nome sem acento, registro ou CNPJ', function () {
    ansCovenant(['name' => 'SAÚDE NORTE', 'ans_registry' => '111111', 'uf' => 'AM', 'national_registry' => '11222333000181']);
    ansCovenant(['name' => 'COOP SUL', 'ans_registry' => '222222', 'uf' => 'RS', 'ans_modality' => 'Cooperativa Médica',
        'ans_status'    => 'cancelled', 'active' => false]);
    Covenant::factory()->create(['name' => 'MANUAL LOCAL']);

    $names = fn (array $q) => collect(covenantRows($q))->pluck('name')->sort()->values()->all();

    expect($names(['source' => 'ans']))->toBe(['COOP SUL', 'SAÚDE NORTE'])
        ->and($names(['source' => 'manual']))->toBe(['MANUAL LOCAL', 'PARTICULAR'])
        ->and($names(['modality' => 'Cooperativa Médica']))->toBe(['COOP SUL'])
        ->and($names(['uf' => 'AM']))->toBe(['SAÚDE NORTE'])
        ->and($names(['cancelled' => 1]))->toBe(['COOP SUL'])
        ->and($names(['status' => 'inactive']))->toBe(['COOP SUL'])
        ->and($names(['search' => 'saude']))->toBe(['SAÚDE NORTE'])
        ->and($names(['search' => '2222']))->toBe(['COOP SUL'])
        ->and($names(['search' => '11.222.333']))->toBe(['SAÚDE NORTE'])
        // Curinga do LIKE digitado é literal.
        ->and($names(['search' => '%']))->toBe([])
        // Filtro fora da lista é ignorado.
        ->and($names(['modality' => "x' or 1=1 --", 'uf' => 'XX']))->toHaveCount(4);
});

it('ordena pela coluna escolhida; coluna fora da lista é ignorada', function () {
    ansCovenant(['name' => 'B OPER', 'ans_registry' => '300000']);
    ansCovenant(['name' => 'A OPER', 'ans_registry' => '100000']);
    ansCovenant(['name' => 'C OPER', 'ans_registry' => '200000']);

    $names = fn (array $q) => array_column(covenantRows(['source' => 'ans', ...$q]), 'name');

    expect($names([]))->toBe(['A OPER', 'B OPER', 'C OPER'])
        ->and($names(['sort' => 'ans_registry', 'direction' => 'desc']))->toBe(['B OPER', 'C OPER', 'A OPER'])
        ->and($names(['sort' => 'name;drop table covenants', 'direction' => 'sideways']))->toBe(['A OPER', 'B OPER', 'C OPER']);
});

it('cria convênio manual GLOBAL (entity_id nulo, nunca a entity SaaS) com dados normalizados', function () {
    asCovenantsAdmin()->post(route('manager.covenants.store'), [
        'name'              => '  saúde local ',
        'color'             => '#ff0000',
        'company_name'      => 'Saúde Local Ltda',
        'national_registry' => '11.222.333/0001-81',
        'ans_registry'      => '9876',
        'ans_modality'      => 'Autogestão',
        'uf'                => 'mg',
        'table'             => false,
    ])->assertSessionHasErrors('ans_registry'); // 4 dígitos

    asCovenantsAdmin()->post(route('manager.covenants.store'), [
        'name'              => '  saúde local ',
        'color'             => '#ff0000',
        'company_name'      => 'Saúde Local Ltda',
        'national_registry' => '11.222.333/0001-81',
        'ans_registry'      => '009876',
        'ans_modality'      => 'Autogestão',
        'uf'                => 'mg',
        'table'             => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c = Covenant::withoutGlobalScopes()->where('name', 'SAÚDE LOCAL')->sole();
    expect($c->entity_id)->toBeNull()
        ->and($c->source)->toBe(CovenantSource::Manual)
        ->and($c->national_registry)->toBe('11222333000181')
        ->and($c->ans_registry)->toBe('009876')
        ->and($c->uf)->toBe('MG')
        ->and($c->color)->toBe('#FF0000')
        ->and($c->table)->toBeFalse()
        ->and($c->active)->toBeTrue()
        ->and($c->code)->toStartWith('CVP');
});

it('valida nome/registro únicos no catálogo global, CNPJ, UF, modalidade e cor', function () {
    ansCovenant(['name' => 'JA EXISTE', 'ans_registry' => '555555']);
    // Convênio de clínica com o mesmo nome NÃO bloqueia o catálogo global.
    Covenant::factory()->create(['entity_id' => $this->clinic->id, 'name' => 'SO NA CLINICA']);

    asCovenantsAdmin()->post(route('manager.covenants.store'), ['name' => 'ja existe', 'color' => '#000000'])
        ->assertSessionHasErrors(['name' => __('manager_covenants.name_taken')]);
    asCovenantsAdmin()->post(route('manager.covenants.store'), ['name' => 'NOVO', 'color' => '#000000', 'ans_registry' => '555555'])
        ->assertSessionHasErrors(['ans_registry' => __('manager_covenants.ans_registry_taken')]);
    asCovenantsAdmin()->post(route('manager.covenants.store'), [
        'name' => 'NOVO', 'color' => 'vermelho', 'national_registry' => '123', 'uf' => 'XX', 'ans_modality' => 'Plano de bairro',
    ])->assertSessionHasErrors(['color', 'national_registry', 'uf', 'ans_modality']);

    asCovenantsAdmin()->post(route('manager.covenants.store'), ['name' => 'SO NA CLINICA', 'color' => '#000000'])
        ->assertSessionHasNoErrors();
});

it('aceita CNPJ alfanumérico (IN RFB 2.229/2024) e busca por ele', function () {
    asCovenantsAdmin()->post(route('manager.covenants.store'), [
        'name' => 'CNPJ NOVO', 'color' => '#000000', 'national_registry' => '12.abc.345/01de-35',
    ])->assertSessionHasNoErrors();

    expect(Covenant::withoutGlobalScopes()->where('name', 'CNPJ NOVO')->value('national_registry'))->toBe('12ABC34501DE35')
        ->and(array_column(covenantRows(['search' => '12.ABC.345']), 'name'))->toBe(['CNPJ NOVO']);

    // DVs (2 últimas posições) são sempre numéricos.
    asCovenantsAdmin()->post(route('manager.covenants.store'), [
        'name' => 'CNPJ ERRADO', 'color' => '#000000', 'national_registry' => '12ABC34501DEXY',
    ])->assertSessionHasErrors(['national_registry' => __('manager_covenants.cnpj_invalid')]);
});

it('operadora da ANS: aceita nome exibido, cor, tabela e ativo; dado oficial é recusado', function () {
    $c = ansCovenant();

    asCovenantsAdmin()->put(route('manager.covenants.update', $c->id), [
        'name' => 'OPX', 'color' => '#00ff00', 'table' => false, 'active' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $c->refresh();
    expect($c->name)->toBe('OPX')->and($c->active)->toBeFalse()->and($c->table)->toBeFalse();

    asCovenantsAdmin()->put(route('manager.covenants.update', $c->id), ['company_name' => 'Outra'])->assertStatus(422);
    asCovenantsAdmin()->put(route('manager.covenants.update', $c->id), ['ans_registry' => '654321'])->assertStatus(422);
    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $c->id), ['reason' => 'Tentativa de excluir operadora da ANS.'])
        ->assertStatus(422);

    expect($c->fresh()->company_name)->toBe('Operadora X Ltda')->and($c->fresh()->trashed())->toBeFalse();
});

it('PARTICULAR: não renomeia, não desativa, não exclui; cor e tabela podem mudar', function () {
    $p = particularCovenant();

    asCovenantsAdmin()->put(route('manager.covenants.update', $p->id), ['name' => 'PARTICULAR 2'])->assertStatus(422);
    asCovenantsAdmin()->put(route('manager.covenants.update', $p->id), ['active' => false])->assertStatus(422);
    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $p->id), ['reason' => 'Tentativa de excluir o particular.'])
        ->assertStatus(422);

    asCovenantsAdmin()->put(route('manager.covenants.update', $p->id), ['color' => '#123456', 'table' => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $p->refresh();
    expect($p->name)->toBe('PARTICULAR')->and($p->active)->toBeTrue()->and($p->color)->toBe('#123456');
});

it('[AUDITORIA] exclui (soft) convênio manual sem uso, com justificativa e trilha', function () {
    $c = Covenant::factory()->create(['name' => 'DUPLICADO']);

    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $c->id))->assertSessionHasErrors('reason');
    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $c->id), ['reason' => 'curto'])->assertSessionHasErrors('reason');
    expect($c->fresh()->trashed())->toBeFalse();

    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $c->id), ['reason' => 'Duplicado da operadora importada da ANS.'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Covenant::withoutGlobalScopes()->withTrashed()->find($c->id)->trashed())->toBeTrue();

    $log = DB::table('audit_logs')->where('event', 'manager.covenant.destroy')->sole();
    expect($log->auditable_id)->toBe((string) $c->id)
        ->and($log->user_id)->toBe((string) $this->admin->id)
        ->and($log->reason)->toBe('Duplicado da operadora importada da ANS.');
});

it('convênio manual em uso por clínica não é excluído (cascade apagaria pacientes)', function () {
    $c       = Covenant::factory()->create(['name' => 'EM USO']);
    $patient = Patient::factory()->create(['entity_id' => $this->clinic->id, 'covenant_id' => $c->id]);

    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $c->id), ['reason' => 'Quero excluir mesmo estando em uso.'])
        ->assertSessionHasErrors(['reason' => __('manager_covenants.in_use')]);

    expect($c->fresh()->trashed())->toBeFalse()
        ->and(Patient::withoutGlobalScopes()->find($patient->id))->not->toBeNull()
        ->and(DB::table('audit_logs')->where('event', 'manager.covenant.destroy')->exists())->toBeFalse();
});

it('convênio manual com plano próprio de clínica é "em uso"; os planos manuais globais saem junto', function () {
    $withClinicPlan = Covenant::factory()->create(['name' => 'COM PLANO DE CLINICA']);
    CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => $this->clinic->id, 'covenant_id' => $withClinicPlan->id, 'name' => 'DA CLINICA', 'active' => true,
    ]);

    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $withClinicPlan->id), ['reason' => 'Convênio duplicado no catálogo global.'])
        ->assertSessionHasErrors(['reason' => __('manager_covenants.in_use')]);
    expect($withClinicPlan->fresh()->trashed())->toBeFalse();

    $onlyGlobal = Covenant::factory()->create(['name' => 'SO COM PLANO GLOBAL']);
    $globalPlan = CovenantPlan::withoutGlobalScopes()->forceCreate([
        'entity_id' => null, 'covenant_id' => $onlyGlobal->id, 'name' => 'MANUAL', 'source' => 'manual', 'active' => true,
    ]);

    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $onlyGlobal->id), ['reason' => 'Convênio duplicado no catálogo global.'])
        ->assertSessionHasNoErrors();

    expect($onlyGlobal->fresh()->trashed())->toBeTrue()
        ->and($globalPlan->fresh()->trashed())->toBeTrue();
});

it('uso pelas clínicas: conta clínicas distintas, pacientes, agenda, guias e preços', function () {
    $c     = ansCovenant();
    $other = Entity::factory()->create(['is_client' => true]);
    Patient::factory()->count(2)->create(['entity_id' => $this->clinic->id, 'covenant_id' => $c->id]);
    Patient::factory()->create(['entity_id' => $other->id, 'covenant_id' => $c->id]);

    asCovenantsAdmin()->getJson(route('manager.covenants.usage', $c->id))
        ->assertOk()
        ->assertJson(['data' => ['clinics' => 2, 'patients' => 3, 'schedules' => 0, 'claims' => 0, 'prices' => 0]]);
});

it('[SEGURANÇA] convênio de clínica não é alcançável pelo manager (404)', function () {
    $own = Covenant::factory()->create(['entity_id' => $this->clinic->id, 'name' => 'DA CLINICA']);

    asCovenantsAdmin()->put(route('manager.covenants.update', $own->id), ['active' => false])->assertNotFound();
    asCovenantsAdmin()->delete(route('manager.covenants.destroy', $own->id), ['reason' => 'Tentativa em convênio de clínica.'])
        ->assertNotFound();
    asCovenantsAdmin()->getJson(route('manager.covenants.usage', $own->id))->assertNotFound();
    // id que não é UUID: 404 (sem erro de SQL).
    asCovenantsAdmin()->getJson('/panel/manager/covenants/nao-e-uuid/usage')->assertNotFound();

    expect($own->fresh()->active)->toBeTrue();
});

it('[SEGURANÇA] papel SaaS não-admin (suporte) não acessa nem vê o item no menu', function () {
    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);

    $this->actingAs($support)->withSession(covenantsManagerSession($this->saas, SaasRule::Support->value))
        ->get(route('manager.covenants.index'))
        ->assertForbidden();

    $this->actingAs($support)->withSession(covenantsManagerSession($this->saas, SaasRule::Support->value))
        ->post(route('manager.covenants.imports.store'), ['source' => 'ans', 'modalities' => ['Autogestão']])
        ->assertForbidden();
});

it('menu do manager: catálogos (medicamentos e convênios) só pra quem abre a tela', function (string $rule, bool $owner, bool $visible) {
    $user = User::factory()->create();
    createEntityUser($this->saas, $user, $rule, isOwner: $owner);

    test()->actingAs($user);
    session(covenantsManagerSession($this->saas, $rule));

    $keys = collect(PanelNavigation::build())->pluck('key')->filter()->values()->all();

    expect(in_array('covenants', $keys, true))->toBe($visible)
        ->and(in_array('medicines', $keys, true))->toBe($visible);

    // Link que aparece abre de verdade (nenhum 403 escondido).
    $status = test()->actingAs($user)->withSession(covenantsManagerSession($this->saas, $rule))
        ->get(route('manager.covenants.index'))->status();
    expect($status)->toBe($visible ? 200 : 403);
})->with([
    'admin'            => [SaasRule::Admin->value, false, true],
    'dono (financial)' => [SaasRule::Financial->value, true, true],
    'financeiro'       => [SaasRule::Financial->value, false, false],
    'suporte'          => [SaasRule::Support->value, false, false],
]);

it('[SEGURANÇA] usuário de clínica não acessa', function () {
    $doctor = User::factory()->create();
    $eu     = createEntityUser($this->clinic, $doctor, 'doctor');

    $response = $this->actingAs($doctor)->withSession(panelSession($eu))->get(route('manager.covenants.index'));

    expect($response->status())->toBeIn([302, 403]);
});

it('"Atualizar agora" enfileira a sincronização com as modalidades escolhidas (sem arquivo)', function () {
    Queue::fake();

    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), [
        'source'     => 'ans',
        'modalities' => ['Autogestão', 'Autogestão', 'Cooperativa Médica'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $import = CovenantImport::query()->sole();
    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->source)->toBe(CovenantImport::SOURCE_ANS)
        ->and($import->user_id)->toBe($this->admin->id)
        ->and($import->modalities)->toBe(['Autogestão', 'Cooperativa Médica'])
        ->and($import->active_file_path)->toBeNull();
    Queue::assertPushed(ProcessCovenantImportJob::class, fn ($job) => $job->import->is($import));
});

it('envio manual exige o CSV de ativas e guarda os arquivos', function () {
    Queue::fake();
    Storage::fake();

    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), ['source' => 'upload', 'modalities' => ['Autogestão']])
        ->assertSessionHasErrors('active_file');

    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), [
        'source'         => 'upload',
        'modalities'     => ['Autogestão'],
        'active_file'    => UploadedFile::fake()->createWithContent('Relatorio_cadop.csv', "REGISTRO_OPERADORA;CNPJ\n"),
        'cancelled_file' => UploadedFile::fake()->createWithContent('Relatorio_cadop_canceladas.csv', "REGISTRO_OPERADORA;CNPJ\n"),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $import = CovenantImport::query()->sole();
    expect($import->source)->toBe(CovenantImport::SOURCE_UPLOAD)
        ->and($import->active_original_name)->toBe('Relatorio_cadop.csv')
        ->and($import->cancelled_original_name)->toBe('Relatorio_cadop_canceladas.csv');
    Storage::disk()->assertExists($import->active_file_path);
    Storage::disk()->assertExists($import->cancelled_file_path);
    Queue::assertPushed(ProcessCovenantImportJob::class);
});

it('recusa modalidade fora da lista, arquivo que não é CSV e sincronização em paralelo', function () {
    Queue::fake();
    Storage::fake();

    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), ['source' => 'ans', 'modalities' => ['Plano de bairro']])
        ->assertSessionHasErrors('modalities.0');
    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), ['source' => 'ans', 'modalities' => []])
        ->assertSessionHasErrors('modalities');
    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), [
        'source'      => 'upload', 'modalities' => ['Autogestão'],
        'active_file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('active_file');

    CovenantImport::query()->create(['source' => CovenantImport::SOURCE_SCHEDULED, 'status' => ImportStatus::Processing, 'modalities' => ['Autogestão']]);
    asCovenantsAdmin()->post(route('manager.covenants.imports.store'), ['source' => 'ans', 'modalities' => ['Autogestão']])
        ->assertSessionHasErrors(['source' => __('manager_covenants.import_in_progress')]);

    Queue::assertNothingPushed();
});

it('tela traz a sincronização em andamento pra barra de progresso (sem endpoint HTTP de status)', function () {
    CovenantImport::query()->create(['source' => CovenantImport::SOURCE_SCHEDULED, 'status' => ImportStatus::Pending, 'modalities' => ['Autogestão']]);

    asCovenantsAdmin()->get(route('manager.covenants.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('runningImport.source', CovenantImport::SOURCE_SCHEDULED)
            ->where('runningImport.source_label', __('manager_covenants.import_source_scheduled'))
            ->where('runningImport.is_done', false)
            ->where('runningImport.channel', fn ($channel) => str_starts_with($channel, 'manager.imports.covenants.')));

    expect(Route::has('manager.covenants.imports.status'))->toBeFalse();
});
