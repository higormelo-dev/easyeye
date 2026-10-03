<?php

use App\Enums\{ImportStatus, MedicineSource, SaasRule};
use App\Jobs\ProcessMedicineImportJob;
use App\Models\{Entity, Medicine, MedicineImport, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/**
 * Manager → Medicamentos: catálogo GLOBAL do receituário, admin SaaS only.
 */
function medicinesManagerSession(Entity $saas, string $rule = 'admin'): array
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

function asMedicinesAdmin()
{
    return test()->actingAs(test()->admin)->withSession(medicinesManagerSession(test()->saas));
}

it('lista só o catálogo global (nunca medicamento de clínica)', function () {
    Medicine::withoutGlobalScopes()->create(['name' => 'GLOBAL X', 'active' => true]);
    Medicine::withoutGlobalScopes()->create(['entity_id' => $this->clinic->id, 'name' => 'DA CLINICA', 'active' => true]);

    asMedicinesAdmin()->get(route('manager.medicines.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Panel/Manager/Medicines/Index')
            ->where('medicines.data', fn ($rows) => collect($rows)->pluck('name')->all() === ['GLOBAL X']));
});

it('cria item curado GLOBAL (entity_id nulo, nunca a entity SaaS da sessão)', function () {
    asMedicinesAdmin()->post(route('manager.medicines.store'), [
        'name'              => 'TIMOLOL 0,5%',
        'active_ingredient' => 'maleato de timolol',
        'dosage'            => '1 gota',
        'frequency'         => 'de 12/12h',
        'is_ophthalmic'     => true,
        'active'            => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $m = Medicine::withoutGlobalScopes()->where('name', 'TIMOLOL 0,5%')->sole();
    expect($m->entity_id)->toBeNull()
        ->and($m->source)->toBe(MedicineSource::Manual)
        ->and($m->search_text)->toContain('maleato de timolol');
});

it('item da CMED: aceita só a posologia; cadastro/ativo e exclusão são recusados', function () {
    $cmed = Medicine::withoutGlobalScopes()->create([
        'name' => 'PREDOPTIC', 'source' => MedicineSource::Cmed, 'source_code' => '1', 'active' => true,
    ]);

    asMedicinesAdmin()->put(route('manager.medicines.update', $cmed->id), ['dosage' => '1 gota', 'frequency' => 'de 6/6h'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($cmed->fresh()->dosage)->toBe('1 gota');

    asMedicinesAdmin()->put(route('manager.medicines.update', $cmed->id), ['active' => false])->assertStatus(422);
    asMedicinesAdmin()->put(route('manager.medicines.update', $cmed->id), ['name' => 'OUTRO'])->assertStatus(422);
    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $cmed->id))->assertStatus(422);

    expect($cmed->fresh()->active)->toBeTrue()->and($cmed->fresh()->name)->toBe('PREDOPTIC');
});

it('desativa e exclui (soft) item curado', function () {
    $m = Medicine::withoutGlobalScopes()->create(['name' => 'CURADO', 'active' => true]);

    asMedicinesAdmin()->put(route('manager.medicines.update', $m->id), ['active' => false])->assertRedirect();
    expect($m->fresh()->active)->toBeFalse();

    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $m->id), ['reason' => 'Duplicado do item importado da CMED.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Medicine::withoutGlobalScopes()->withTrashed()->find($m->id)->trashed())->toBeTrue();
});

it('[AUDITORIA] excluir exige justificativa (mín. 20) e grava a trilha administrativa', function () {
    $m = Medicine::withoutGlobalScopes()->create(['name' => 'CURADO', 'active' => true]);

    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $m->id))->assertSessionHasErrors('reason');
    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $m->id), ['reason' => 'curto'])->assertSessionHasErrors('reason');
    expect($m->fresh())->not->toBeNull();

    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $m->id), ['reason' => 'Cadastro errado, substituído pelo da CMED.'])
        ->assertSessionHasNoErrors();

    $log = DB::table('audit_logs')->where('event', 'manager.medicine.destroy')->sole();
    expect($log->auditable_id)->toBe((string) $m->id)
        ->and($log->user_id)->toBe((string) $this->admin->id)
        ->and($log->reason)->toBe('Cadastro errado, substituído pelo da CMED.');
});

it('ordena pela coluna escolhida; coluna fora da lista é ignorada', function () {
    Medicine::withoutGlobalScopes()->create(['name' => 'B MED', 'laboratory' => 'ZETA', 'active' => true]);
    Medicine::withoutGlobalScopes()->create(['name' => 'A MED', 'laboratory' => 'ALFA', 'active' => true]);
    Medicine::withoutGlobalScopes()->create(['name' => 'C MED', 'laboratory' => 'MEIO', 'active' => true]);

    $names = fn (array $query) => asMedicinesAdmin()->get(route('manager.medicines.index', $query))
        ->assertOk()
        ->viewData('page')['props']['medicines']['data'];

    expect(array_column($names([]), 'name'))->toBe(['A MED', 'B MED', 'C MED'])
        ->and(array_column($names(['sort' => 'laboratory', 'direction' => 'desc']), 'name'))->toBe(['B MED', 'C MED', 'A MED'])
        ->and(array_column($names(['sort' => 'name;drop table medicines', 'direction' => 'sideways']), 'name'))->toBe(['A MED', 'B MED', 'C MED']);

    asMedicinesAdmin()->get(route('manager.medicines.index', ['sort' => 'deleted_at']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.sort', 'name')->where('filters.direction', 'asc'));
});

it('situação na CMED separada do status: código na linha, filtro e ordenação próprios', function () {
    $mk = fn (string $name, array $a) => Medicine::withoutGlobalScopes()->create(['name' => $name, 'active' => true, ...$a]);
    $mk('A COMERCIALIZADO', ['source' => MedicineSource::Cmed, 'source_code' => '1', 'is_marketed' => true]);
    $mk('B SEM VENDA', ['source' => MedicineSource::Cmed, 'source_code' => '2', 'is_marketed' => false]);
    $mk('C FORA DA LISTA', ['source' => MedicineSource::Cmed, 'source_code' => '3', 'is_marketed' => true, 'active' => false]);
    $mk('D CURADO', []);

    $rows = fn (array $query) => collect(asMedicinesAdmin()->get(route('manager.medicines.index', $query))
        ->assertOk()->viewData('page')['props']['medicines']['data']);

    expect($rows([])->pluck('cmed_situation', 'name')->all())->toBe([
        'A COMERCIALIZADO' => 'marketed', 'B SEM VENDA' => 'not_marketed', 'C FORA DA LISTA' => 'left_list', 'D CURADO' => null,
    ]);

    expect($rows(['cmed_situation' => 'not_marketed'])->pluck('name')->all())->toBe(['B SEM VENDA'])
        ->and($rows(['cmed_situation' => 'left_list'])->pluck('name')->all())->toBe(['C FORA DA LISTA'])
        ->and($rows(['cmed_situation' => 'marketed'])->pluck('name')->all())->toBe(['A COMERCIALIZADO'])
        ->and($rows(['cmed_situation' => 'qualquer'])->count())->toBe(4);

    expect($rows(['sort' => 'cmed_situation'])->pluck('name')->all())
        ->toBe(['A COMERCIALIZADO', 'B SEM VENDA', 'C FORA DA LISTA', 'D CURADO'])
        ->and($rows(['sort' => 'cmed_situation', 'direction' => 'desc'])->pluck('name')->all())
        ->toBe(['D CURADO', 'C FORA DA LISTA', 'B SEM VENDA', 'A COMERCIALIZADO']);

    // Status (Ativo/Inativo) continua filtrando à parte.
    expect($rows(['status' => 'inactive'])->pluck('name')->all())->toBe(['C FORA DA LISTA']);
});

it('busca mantém a ordenação escolhida', function () {
    Medicine::withoutGlobalScopes()->create(['name' => 'TIMOLOL GENÉRICO', 'source' => MedicineSource::Cmed, 'source_code' => '9', 'active' => true]);
    Medicine::withoutGlobalScopes()->create(['name' => 'TIMOLOL CURADO', 'active' => true]);

    $names = fn (array $query) => array_column(asMedicinesAdmin()->get(route('manager.medicines.index', $query))
        ->viewData('page')['props']['medicines']['data'], 'name');

    expect($names(['search' => 'timolol']))->toBe(['TIMOLOL CURADO', 'TIMOLOL GENÉRICO'])
        ->and($names(['search' => 'timolol', 'sort' => 'name', 'direction' => 'desc']))->toBe(['TIMOLOL GENÉRICO', 'TIMOLOL CURADO']);
});

it('[SEGURANÇA] medicamento de clínica não é alcançável pelo manager (404)', function () {
    $own = Medicine::withoutGlobalScopes()->create(['entity_id' => $this->clinic->id, 'name' => 'DA CLINICA', 'active' => true]);

    asMedicinesAdmin()->put(route('manager.medicines.update', $own->id), ['active' => false])->assertNotFound();
    asMedicinesAdmin()->delete(route('manager.medicines.destroy', $own->id))->assertNotFound();
    expect($own->fresh()->active)->toBeTrue();
});

it('[SEGURANÇA] papel SaaS não-admin (suporte) não acessa', function () {
    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);

    $this->actingAs($support)->withSession(medicinesManagerSession($this->saas, SaasRule::Support->value))
        ->get(route('manager.medicines.index'))
        ->assertForbidden();
});

it('[SEGURANÇA] usuário de clínica não acessa', function () {
    $doctor = User::factory()->create();
    $eu     = createEntityUser($this->clinic, $doctor, 'doctor');

    $response = $this->actingAs($doctor)->withSession(panelSession($eu))->get(route('manager.medicines.index'));

    expect($response->status())->toBeIn([302, 403]);
});

it('upload da lista CMED guarda os arquivos e enfileira a importação', function () {
    Queue::fake();
    Storage::fake();

    asMedicinesAdmin()->post(route('manager.medicines.imports.store'), [
        'cmed_file'      => UploadedFile::fake()->create('lista_pmc.xlsx', 100),
        'open_data_file' => UploadedFile::fake()->create('DADOS_ABERTOS_MEDICAMENTOS.csv', 50, 'text/csv'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $import = MedicineImport::query()->sole();
    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->user_id)->toBe($this->admin->id)
        ->and($import->cmed_original_name)->toBe('lista_pmc.xlsx');
    Storage::disk()->assertExists($import->cmed_file_path);
    Storage::disk()->assertExists($import->open_data_file_path);
    Queue::assertPushed(ProcessMedicineImportJob::class);
});

it('não aceita nova importação com outra em andamento', function () {
    Queue::fake();
    Storage::fake();
    MedicineImport::query()->create([
        'status' => ImportStatus::Processing, 'cmed_file_path' => 'x', 'cmed_original_name' => 'x.xlsx',
    ]);

    asMedicinesAdmin()->post(route('manager.medicines.imports.store'), [
        'cmed_file' => UploadedFile::fake()->create('lista_pmc.xlsx', 100),
    ])->assertSessionHasErrors('cmed_file');

    Queue::assertNothingPushed();
});

it('recusa arquivo que não é planilha/CSV', function () {
    Queue::fake();

    asMedicinesAdmin()->post(route('manager.medicines.imports.store'), [
        'cmed_file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
    ])->assertSessionHasErrors('cmed_file');

    Queue::assertNothingPushed();
});

it('não existe mais endpoint HTTP de status (progresso só por WebSocket)', function () {
    expect(Route::has('manager.medicines.imports.status'))->toBeFalse();
});

it('tela traz a importação em andamento pra barra de progresso', function () {
    MedicineImport::query()->create([
        'status' => ImportStatus::Pending, 'cmed_file_path' => 'x', 'cmed_original_name' => 'lista_pmc.xlsx',
    ]);

    asMedicinesAdmin()->get(route('manager.medicines.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('runningImport.cmed_original_name', 'lista_pmc.xlsx')
            ->where('runningImport.is_done', false)
            ->where('runningImport.channel', fn ($channel) => str_starts_with($channel, 'manager.imports.medicines.')));
});
