<?php

use App\Enums\{ClientRule, DiagnosisType, SaasRule};
use App\Models\{Cid10Code, Entity, EntityDiagnosisUsage, MedicalRecord, Patient, PatientExam, User};
use App\Services\Cid10\Cid10UsageStats;
use App\Services\Cid10CatalogImporter;
use App\Support\PanelNavigation;
use Illuminate\Support\Facades\{DB, Http};
use Inertia\Testing\AssertableInertia;

/**
 * Manager → CID-10: catálogo GLOBAL de diagnósticos, admin SaaS only. O
 * manager pode criar/editar/excluir, com as salvaguardas: oficial guardado
 * à parte, código em uso travado, justificativa + trilha nas ações sobre
 * código oficial e na exclusão, uso agregado sem dado de paciente.
 */
function cid10ManagerSession(Entity $saas, string $rule = 'admin'): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ];
}

function asCid10Admin()
{
    return test()->actingAs(test()->admin)->withSession(cid10ManagerSession(test()->saas));
}

/** Props da tela (com filtros/ordenação na query). */
function cid10Props(array $query = []): array
{
    return asCid10Admin()->get(route('manager.cid10.index', $query))->assertOk()->viewData('page')['props'];
}

function cid10Record(Entity $entity, array $cids, bool $signed = false): MedicalRecord
{
    $record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => Patient::factory()->create(['entity_id' => $entity->id])->id,
        'doctor_id'      => createDoctorForEntity($entity)->id,
        'diagnosis_cids' => $cids,
    ]);

    if ($signed) {
        DB::table('medical_records')->where('id', $record->id)->update(['signed_at' => now()]);
    }

    return $record->fresh();
}

function cid10Exam(Entity $entity, array $cids): PatientExam
{
    return PatientExam::factory()->create([
        'patient_id'     => Patient::factory()->create(['entity_id' => $entity->id])->id,
        'diagnosis_cids' => $cids,
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->clinic = Entity::factory()->create(['is_client' => true, 'active' => true, 'name' => 'Clínica Visão']);
});

const CID_REASON = 'Texto oficial confunde os médicos da oftalmologia.';

describe('listagem, filtros e KPIs', function () {
    it('mostra o código com oficial, capítulo, grupo e origem; KPIs do catálogo', function () {
        $props = cid10Props(['search' => 'H25.1']);
        $row   = collect($props['codes']['data'])->firstWhere('code', 'H25.1');

        expect($props)->toHaveKey('t')
            ->and($row)->toMatchArray([
                'description'          => 'Catarata senil nuclear',
                'official_description' => 'Catarata senil nuclear',
                'chapter'              => 'VII',
                'chapter_name'         => 'Doenças do olho e anexos',
                'source'               => 'datasus',
                'is_custom'            => false,
                'is_edited'            => false,
            ])
            ->and($row['group_name'])->toContain('cristalino')
            ->and($props['stats'])->toMatchArray([
                'total'    => Cid10Code::count(),
                'official' => Cid10Code::where('source', 'datasus')->count(),
                'custom'   => Cid10Code::where('source', 'custom')->count(),
                'edited'   => 0,
                'used'     => 0,
                'review'   => 0,
            ])
            ->and(collect($props['chapters'])->pluck('chapter')->all())->toHaveCount(22)
            ->and($props['chapters'][6])->toMatchArray(['chapter' => 'VII', 'start' => 'H00', 'end' => 'H59']);
    });

    it('filtra por capítulo, categoria, origem e editados; categorias seguem o capítulo', function () {
        Cid10Code::where('code', 'H25.1')->update(['description' => 'Catarata nuclear', 'description_edited_at' => now()]);

        $vii = cid10Props(['chapter' => 'VII']);
        expect(collect($vii['codes']['data'])->pluck('chapter')->unique()->all())->toBe(['VII'])
            ->and($vii['codes']['total'])->toBe(Cid10Code::where('chapter', 'VII')->count())
            ->and($vii['categories'])->toContain('Pálpebras')->not->toContain('Doenças infecciosas intestinais');

        expect(collect(cid10Props(['category' => 'Pálpebras'])['codes']['data'])->pluck('category')->unique()->all())->toBe(['Pálpebras'])
            ->and(collect(cid10Props(['source' => 'custom'])['codes']['data'])->pluck('code')->all())->toBe(['B30'])
            ->and(collect(cid10Props(['edited' => 1])['codes']['data'])->pluck('code')->all())->toBe(['H25.1'])
            // valor fora da lista é ignorado
            ->and(cid10Props(['chapter' => 'XCIX', 'source' => 'outro'])['filters'])->toMatchArray(['chapter' => '', 'source' => '']);
    });

    it('ordena por capítulo na ordem numérica (II antes de IX) e coluna fora da lista é ignorada', function () {
        $chapters = collect(cid10Props(['sort' => 'chapter', 'direction' => 'desc'])['codes']['data'])->pluck('chapter');
        expect($chapters->first())->toBe('XXII');

        $props = cid10Props(['sort' => 'code;drop table cid10_codes', 'direction' => 'x']);
        expect($props['filters'])->toMatchArray(['sort' => 'code', 'direction' => 'asc'])
            ->and($props['codes']['data'][0]['code'])->toBe('A00.0');
    });
});

describe('criar código (personalizado)', function () {
    it('cria normalizado (maiúsculo), personalizado, com capítulo/grupo da faixa e auditoria', function () {
        asCid10Admin()->post(route('manager.cid10.store'), [
            'code' => ' h59.7 ', 'description' => 'Complicação pós-operatória ocular (interna)', 'category' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $code = Cid10Code::where('code', 'H59.7')->sole();
        expect($code->only(['source', 'chapter', 'official_description', 'created_by']))->toBe([
            'source' => 'custom', 'chapter' => 'VII', 'official_description' => null, 'created_by' => $this->admin->id,
        ])
            ->and($code->group_name)->not->toBeNull()
            ->and($code->category)->toBe($code->group_name)
            ->and(DB::table('audit_logs')->where('auditable_type', Cid10Code::class)->where('auditable_id', $code->id)->where('event', 'created')->exists())->toBeTrue();
    });

    it('[422] recusa formato fora da CID-10 e código repetido', function () {
        foreach (['H5', '125', 'H25.12', 'HH5', 'H25-1', ''] as $invalid) {
            asCid10Admin()->post(route('manager.cid10.store'), ['code' => $invalid, 'description' => 'X'])
                ->assertSessionHasErrors('code');
        }

        asCid10Admin()->post(route('manager.cid10.store'), ['code' => 'H5', 'description' => 'X'])
            ->assertSessionHasErrors(['code' => __('manager_cid10.code_format')]);
        asCid10Admin()->post(route('manager.cid10.store'), ['code' => 'h25.1', 'description' => 'Duplicado'])
            ->assertSessionHasErrors(['code' => __('manager_cid10.code_taken')]);
        asCid10Admin()->postJson(route('manager.cid10.store'), ['code' => 'H59.7'])
            ->assertStatus(422)->assertJsonValidationErrors('description');

        expect(Cid10Code::where('code', 'H59.7')->exists())->toBeFalse();
    });

    it('código personalizado aparece na busca dos médicos com a indicação de fora da tabela oficial', function () {
        Cid10Code::create(['code' => 'H59.7', 'description' => 'Vitreíte pós-operatória zeta', 'source' => 'custom', 'category' => 'Retina']);
        $doctor = User::factory()->create();
        $eu     = createEntityUser($this->clinic, $doctor, ClientRule::Doctor->value);

        $plain = $this->actingAs($doctor)->withSession(panelSession($eu))
            ->getJson(route('panel.cid10.search', ['q' => 'vitreite pos-operatoria zeta']))->assertOk()->json();
        $select = $this->actingAs($doctor)->withSession(panelSession($eu))
            ->getJson(route('panel.cid10.search', ['q' => 'H59.7', 'shape' => 'select']))->assertOk()->json('data');
        $official = $this->actingAs($doctor)->withSession(panelSession($eu))
            ->getJson(route('panel.cid10.search', ['q' => 'H25.1']))->assertOk()->json();

        expect($plain[0])->toMatchArray(['code' => 'H59.7', 'source' => 'custom'])
            ->and($select[0]['sub_label'])->toContain(__('ui.cid10.non_official'))
            ->and(collect($official)->firstWhere('code', 'H25.1')['source'])->toBe('datasus');
    });
});

describe('editar', function () {
    it('descrição de código oficial: exige justificativa; guarda o oficial ao lado, selo editado e trilha', function () {
        $code = Cid10Code::where('code', 'H25.1')->sole();
        $data = ['code' => 'H25.1', 'description' => 'Catarata nuclear senil', 'category' => $code->category];

        asCid10Admin()->put(route('manager.cid10.update', $code->id), $data)->assertSessionHasErrors('reason');
        asCid10Admin()->put(route('manager.cid10.update', $code->id), [...$data, 'reason' => 'curto'])->assertSessionHasErrors('reason');
        expect($code->fresh()->description)->toBe('Catarata senil nuclear');

        asCid10Admin()->put(route('manager.cid10.update', $code->id), [...$data, 'reason' => CID_REASON])
            ->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $code->fresh();
        expect($fresh->description)->toBe('Catarata nuclear senil')
            ->and($fresh->official_description)->toBe('Catarata senil nuclear')
            ->and($fresh->description_edited_at)->not->toBeNull()
            ->and($fresh->description_edited_by)->toBe($this->admin->id)
            ->and($fresh->updated_by)->toBe($this->admin->id);

        $admin = DB::table('audit_logs')->where('event', 'manager.cid10.update')->sole();
        expect($admin->reason)->toBe(CID_REASON)
            ->and($admin->auditable_id)->toBe($code->id)
            ->and(json_decode($admin->old_values, true)['description'])->toBe('Catarata senil nuclear')
            ->and(DB::table('audit_logs')->where('auditable_type', Cid10Code::class)->where('auditable_id', $code->id)->where('event', 'updated')->exists())->toBeTrue();

        $row = collect(cid10Props(['search' => 'H25.1'])['codes']['data'])->firstWhere('code', 'H25.1');
        expect($row)->toMatchArray(['is_edited' => true, 'edited_by' => $this->admin->name, 'official_description' => 'Catarata senil nuclear']);
    });

    it('voltar ao texto oficial tira o selo "editado"; categoria muda sem justificativa', function () {
        $code = Cid10Code::where('code', 'H25.1')->sole();
        $code->update(['description' => 'Texto antigo editado', 'description_edited_at' => now()]);

        asCid10Admin()->put(route('manager.cid10.update', $code->id), [
            'code' => 'H25.1', 'description' => 'Catarata senil nuclear', 'reason' => CID_REASON,
        ])->assertSessionHasNoErrors();
        expect($code->fresh()->description_edited_at)->toBeNull();

        asCid10Admin()->put(route('manager.cid10.update', $code->id), [
            'code' => 'H25.1', 'description' => 'Catarata senil nuclear', 'category' => 'Cristalino',
        ])->assertSessionHasNoErrors();
        expect($code->fresh()->category)->toBe('Cristalino');
    });

    it('código personalizado: descrição muda sem justificativa e nunca vira "editado"', function () {
        $code = Cid10Code::create(['code' => 'H59.7', 'description' => 'Antes', 'source' => 'custom']);

        asCid10Admin()->put(route('manager.cid10.update', $code->id), ['code' => 'H59.7', 'description' => 'Depois'])
            ->assertSessionHasNoErrors();

        expect($code->fresh()->only(['description', 'description_edited_at']))->toBe(['description' => 'Depois', 'description_edited_at' => null]);
    });

    it('[422] trocar o CÓDIGO em uso é recusado com a contagem; descrição e categoria continuam editáveis', function () {
        $code = Cid10Code::create(['code' => 'H59.7', 'description' => 'Personalizado', 'source' => 'custom']);
        cid10Record($this->clinic, [['code' => 'H59.7', 'description' => 'Personalizado']]);
        cid10Exam($this->clinic, [['code' => 'H59.7', 'description' => 'Personalizado']]);

        asCid10Admin()->putJson(route('manager.cid10.update', $code->id), ['code' => 'H54.8', 'description' => 'Personalizado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => __('manager_cid10.code_in_use', [
                'code' => 'H59.7', 'count' => 2, 'records' => 1, 'exams' => 1, 'links' => 0,
            ])]);
        expect($code->fresh()->code)->toBe('H59.7');

        asCid10Admin()->put(route('manager.cid10.update', $code->id), ['code' => 'H59.7', 'description' => 'Novo texto', 'category' => 'Retina'])
            ->assertSessionHasNoErrors();
        expect($code->fresh()->only(['code', 'description', 'category']))->toBe(['code' => 'H59.7', 'description' => 'Novo texto', 'category' => 'Retina']);
    });

    it('trocar o código de um oficial sem uso: vira personalizado (o oficial volta na próxima importação)', function () {
        $code = Cid10Code::where('code', 'H25.8')->sole();

        asCid10Admin()->put(route('manager.cid10.update', $code->id), ['code' => 'H25.7', 'description' => $code->description])
            ->assertSessionHasErrors('reason');

        asCid10Admin()->put(route('manager.cid10.update', $code->id), ['code' => 'H25.7', 'description' => $code->description, 'reason' => CID_REASON])
            ->assertSessionHasNoErrors();

        expect($code->fresh()->only(['code', 'source', 'official_description', 'chapter']))->toBe([
            'code' => 'H25.7', 'source' => 'custom', 'official_description' => null, 'chapter' => 'VII',
        ]);

        app(Cid10CatalogImporter::class)->import();
        expect(Cid10Code::where('code', 'H25.8')->value('source'))->toBe('datasus')
            ->and(Cid10Code::where('code', 'H25.7')->value('source'))->toBe('custom');
    });
});

describe('excluir', function () {
    it('[422] bloqueado se usado em prontuário, exame ou por clínica (com quantos registros); nada é apagado', function () {
        $code = Cid10Code::where('code', 'H40.1')->sole();
        cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma']], signed: true);
        cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        EntityDiagnosisUsage::create([
            'entity_id' => $this->clinic->id, 'diagnosis_type' => DiagnosisType::Cid10, 'cid10_code_id' => $code->id, 'use_count' => 3,
        ]);

        asCid10Admin()->deleteJson(route('manager.cid10.destroy', $code->id), ['reason' => CID_REASON])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code' => __('manager_cid10.delete_in_use', [
                'code' => 'H40.1', 'count' => 3, 'records' => 2, 'exams' => 0, 'links' => 1,
            ])]);

        expect($code->fresh())->not->toBeNull()
            ->and(DB::table('audit_logs')->where('event', 'manager.cid10.destroy')->exists())->toBeFalse();
    });

    it('sem uso: exige justificativa, exclui, grava a trilha — e o oficial volta na próxima importação', function () {
        $code = Cid10Code::where('code', 'H25.8')->sole();

        asCid10Admin()->delete(route('manager.cid10.destroy', $code->id))->assertSessionHasErrors('reason');
        asCid10Admin()->delete(route('manager.cid10.destroy', $code->id), ['reason' => 'curto'])->assertSessionHasErrors('reason');
        expect($code->fresh())->not->toBeNull();

        asCid10Admin()->delete(route('manager.cid10.destroy', $code->id), ['reason' => CID_REASON])
            ->assertRedirect()->assertSessionHasNoErrors();

        expect(Cid10Code::where('code', 'H25.8')->exists())->toBeFalse();
        $log = DB::table('audit_logs')->where('event', 'manager.cid10.destroy')->sole();
        expect($log->reason)->toBe(CID_REASON)
            ->and($log->user_id)->toBe($this->admin->id)
            ->and(json_decode($log->new_values, true)['code'])->toBe('H25.8')
            ->and(DB::table('audit_logs')->where('auditable_type', Cid10Code::class)->where('auditable_id', $code->id)->where('event', 'deleted')->exists())->toBeTrue();

        expect(app(Cid10CatalogImporter::class)->import()['inserted'])->toBe(1)
            ->and(Cid10Code::where('code', 'H25.8')->value('description'))->toBe($code->description);
    });
});

describe('uso pelas clínicas (agregado, sem paciente)', function () {
    it('conta prontuários e exames por código e clínicas, ordena por uso e filtra usados/sem uso', function () {
        $other = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $rec   = cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma'], ['code' => 'h52.1 ', 'description' => 'Miopia']]);
        cid10Record($other, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        cid10Exam($other, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        // repetido no mesmo prontuário conta 1; JSON fora do formato é ignorado; excluído não conta
        cid10Record($this->clinic, [['code' => 'H52.1'], ['code' => 'H52.1']]);
        $deleted = cid10Record($this->clinic, [['code' => 'H40.1']]);
        $deleted->delete();
        DB::table('medical_records')->where('id', cid10Record($this->clinic, [])->id)->update(['diagnosis_cids' => json_encode(['code' => 'H40.1'])]);

        $props = cid10Props(['sort' => 'usage', 'direction' => 'desc']);
        $rows  = collect($props['codes']['data']);

        expect($rows[0]['code'])->toBe('H40.1')
            ->and($rows[0]['usage'])->toBe(['records' => 2, 'exams' => 1, 'clinics' => 2, 'links' => 0, 'total' => 3])
            ->and($rows[1]['code'])->toBe('H52.1')
            ->and($rows[1]['usage'])->toMatchArray(['records' => 2, 'exams' => 0, 'clinics' => 1])
            ->and($props['stats']['used'])->toBe(2)
            ->and($props['usageAt'])->not->toBeNull()
            ->and(collect(cid10Props(['usage' => 'used'])['codes']['data'])->pluck('code')->sort()->values()->all())->toBe(['H40.1', 'H52.1'])
            ->and(cid10Props(['usage' => 'unused', 'search' => 'H40.1'])['codes']['total'])->toBe(0)
            // nada de paciente na resposta
            ->and(json_encode($props))->not->toContain($rec->patient->person->full_name);
    });

    it('agregado fica em cache por 15 min (sem varrer a cada clique) e recalcula depois', function () {
        cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        $usage = fn () => collect(cid10Props(['search' => 'H40.1'])['codes']['data'])->firstWhere('code', 'H40.1')['usage']['records'];

        expect($usage())->toBe(1);

        cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        expect($usage())->toBe(1);

        $this->travel(Cid10UsageStats::TTL_SECONDS + 60)->seconds();
        expect($usage())->toBe(2);
    });
});

describe('registros a revisar', function () {
    it('card resume por clínica; a gaveta carrega a lista sob demanda, sem dados do paciente', function () {
        $signed = cid10Record($this->clinic, [['code' => 'H50.5', 'description' => 'Estrabismo paralítico']], signed: true);
        cid10Record($this->clinic, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
        $exam = cid10Exam($this->clinic, [['code' => 'B00.3', 'description' => 'Doença ocular herpética']]);

        asCid10Admin()->get(route('manager.cid10.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Panel/Manager/Cid10/Index')
                ->where('stats.review', 2)
                ->where('review.total', 2)
                ->where('review.clinics.0', ['entity' => $this->clinic->name, 'records' => 1, 'exams' => 1, 'signed' => 1, 'total' => 2])
                ->missing('reviewRecords')
                ->reloadOnly('reviewRecords', fn (AssertableInertia $reload) => $reload
                    ->has('reviewRecords', 2)
                    ->where('reviewRecords', fn ($rows) => collect($rows)->pluck('code')->sort()->values()->all() === collect([$signed->code, $exam->code])->sort()->values()->all())
                    ->where('reviewRecords', fn ($rows) => collect($rows)->firstWhere('cid', 'H50.5') == [
                        'entity' => $this->clinic->name, 'kind' => 'record', 'code' => $signed->code, 'cid' => 'H50.5',
                        'text'   => 'Estrabismo paralítico', 'signed' => true, 'old_text' => 'Estrabismo paralítico', 'official' => 'Heteroforia',
                    ])
                    ->where('reviewRecords', fn ($rows) => ! str_contains(json_encode($rows), $signed->patient->person->full_name))));
    });
});

describe('[SEGURANÇA] acesso', function () {
    it('papel SaaS não-admin (suporte) não acessa nenhuma rota', function () {
        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $code = Cid10Code::where('code', 'H25.8')->sole();
        $as   = fn () => $this->actingAs($support)->withSession(cid10ManagerSession($this->saas, SaasRule::Support->value));

        $as()->get(route('manager.cid10.index'))->assertForbidden();
        $as()->post(route('manager.cid10.store'), ['code' => 'H59.7', 'description' => 'X'])->assertForbidden();
        $as()->put(route('manager.cid10.update', $code->id), ['code' => 'H25.8', 'description' => 'X'])->assertForbidden();
        $as()->delete(route('manager.cid10.destroy', $code->id), ['reason' => CID_REASON])->assertForbidden();

        expect($code->fresh())->not->toBeNull()->and(Cid10Code::where('code', 'H59.7')->exists())->toBeFalse();
    });

    it('usuário de clínica não acessa', function () {
        $doctor = User::factory()->create();
        $eu     = createEntityUser($this->clinic, $doctor, 'admin');

        $response = $this->actingAs($doctor)->withSession(panelSession($eu))->get(route('manager.cid10.index'));

        expect($response->status())->toBeIn([302, 403]);
    });

    it('menu do manager mostra CID-10 só para admin/dono', function () {
        asCid10Admin()->get(route('manager.cid10.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nav', fn ($nav) => str_contains(json_encode($nav), 'manager.cid10.index')));

        $support = User::factory()->create();
        createEntityUser($this->saas, $support, SaasRule::Support->value);
        $this->actingAs($support);
        session(cid10ManagerSession($this->saas, SaasRule::Support->value));

        expect(json_encode(PanelNavigation::build()))->not->toContain('manager.cid10.index');
    });
});

it('i18n: pt_BR e en com as mesmas chaves e os mesmos marcadores (:code, :count…)', function () {
    $pt           = require lang_path('pt_BR/manager_cid10.php');
    $en           = require lang_path('en/manager_cid10.php');
    $placeholders = function (string $text): array {
        preg_match_all('/:[a-z_]+/', $text, $m);
        $found = array_unique($m[0]);
        sort($found);

        return $found;
    };

    expect(array_keys($en))->toEqualCanonicalizing(array_keys($pt));

    foreach ($pt as $key => $text) {
        expect($text)->not->toBe('')
            ->and($placeholders($en[$key]))->toBe($placeholders($text), "marcadores diferentes em {$key}");
    }

    expect(__('ui.cid10.non_official', [], 'en'))->not->toBe('ui.cid10.non_official')
        ->and(__('ui.cid10.non_official', [], 'pt_BR'))->not->toBe('ui.cid10.non_official');
});
