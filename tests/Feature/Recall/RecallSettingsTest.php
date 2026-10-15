<?php

declare(strict_types=1);

/**
 * Configurar retornos (Fase 6): quem vê e quem decide, o que a tela mostra de cada protocolo (a prova de quem
 * validou, a versão, o que mudou), a ativação/desativação pelo médico, o retorno pedido pelo médico e o mapeamento
 * dos exames — sempre isolado por clínica.
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Services\ReviewAcceptanceService;
use App\Domains\Recall\Models\{PatientRecall, RecallClinicProtocol, RecallClinicSettings, RecallExamMapping};
use App\Domains\Recall\Services\{ProtocolActivationService, ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Enums\ClientRule;
use App\Models\{EntityUser, ExamType, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Notification};
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->doctorEu   = EntityUser::query()->findOrFail($this->doctor->entity_user_id);
    $this->doctorUser = User::query()->findOrFail($this->doctorEu->user_id);

    foreach (['secretary' => ClientRule::Secretary, 'admin' => ClientRule::Admin, 'financial' => ClientRule::Financial] as $name => $rule) {
        $user                 = User::factory()->create();
        $this->{$name}        = $user;
        $this->{$name . 'Eu'} = createEntityUser($this->entity, $user, $rule->value);
    }
});

function rstAs(object $test, User $user, EntityUser $eu): object
{
    return $test->actingAs($user)->withSession(panelSession($eu));
}

function rstPage(object $test, User $user, EntityUser $eu): array
{
    $props = null;

    rstAs($test, $user, $eu)->get(route('panel.recall.settings.index'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $page->component('Panel/Recall/Settings');
        $props = $page->toArray()['props'];
    });

    return $props;
}

function rstProtocol(array $props, string $key): ?array
{
    return collect($props['protocols'])->firstWhere('key', $key);
}

// ── acesso ──────────────────────────────────────────────────────────────────

it('admin, médico e secretária abrem; o financeiro não; sem a feature no plano, o bloqueio do plano', function () {
    foreach ([[$this->admin, $this->adminEu], [$this->doctorUser, $this->doctorEu], [$this->secretary, $this->secretaryEu]] as [$user, $eu]) {
        rstAs($this, $user, $eu)->get(route('panel.recall.settings.index'))->assertOk();
    }

    rstAs($this, $this->financial, $this->financialEu)->get(route('panel.recall.settings.index'))->assertForbidden();

    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    $user = User::factory()->create();
    $eu   = createEntityUser($noPlan, $user, ClientRule::Admin->value);

    rstAs($this, $user, $eu)->get(route('panel.recall.settings.index'), ['Accept' => 'application/json'])->assertForbidden();
});

it('quem decide e quem configura: o médico decide e configura; o administrador só configura; a secretária só vê', function () {
    $doctor    = rstPage($this, $this->doctorUser, $this->doctorEu);
    $admin     = rstPage($this, $this->admin, $this->adminEu);
    $secretary = rstPage($this, $this->secretary, $this->secretaryEu);

    expect([$doctor['canDecide'], $doctor['canConfigure']])->toBe([true, true])
        ->and([$admin['canDecide'], $admin['canConfigure']])->toBe([false, true])
        ->and([$secretary['canDecide'], $secretary['canConfigure']])->toBe([false, false]);
});

// ── o que a tela mostra ─────────────────────────────────────────────────────

it('mostra o protocolo liberado com a PROVA de quem validou e as tarefas com a faixa; o que não foi liberado nem adotado não aparece', function () {
    $released = rcfReleased('glaucoma');
    crfFrozenVersion('dmri'); // só congelado: a clínica nem o vê

    $props    = rstPage($this, $this->secretary, $this->secretaryEu);
    $protocol = rstProtocol($props, 'glaucoma');

    expect(collect($props['protocols'])->pluck('key')->all())->toBe(['glaucoma'])
        ->and($protocol['status'])->toBe('available')
        ->and($protocol['title'])->toBe('Glaucoma')
        ->and($protocol['released']['id'])->toBe((string) $released->id)
        ->and($protocol['released']['evidence']['reviewer']['name'])->toBe('DRA. ANA PAULA SOUZA')
        ->and($protocol['released']['evidence']['hash_short'])->toHaveLength(12)
        ->and($protocol['adoption'])->toBeNull()
        ->and(collect($protocol['tasks'])->pluck('key')->all())->toBe(['consulta', 'campimetria', 'oct_nervo_optico'])
        ->and($protocol['tasks'][0])->toMatchArray(['key' => 'consulta', 'required' => true, 'unit' => 'months', 'min' => 3, 'max' => 6, 'default' => 4, 'interval' => 4, 'enabled' => true])
        ->and($protocol['tasks'][2])->toMatchArray(['key' => 'oct_nervo_optico', 'required' => false, 'enabled' => false])
        ->and($props['ruleT']['labels']['exams']['visual_field'])->toBeString();
});

it('o revisor que não autorizou aparece como "externo": nenhum nome nem registro na carga da tela', function () {
    $created = crfInvitation(null, [crfFrozenVersion('glaucoma')]);
    crfSubmit($created, attestation: ['allow_identity_display' => false]);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null);
    app(ProtocolGovernanceService::class)->release($created['versions'][0]->fresh());

    $props = rstPage($this, $this->secretary, $this->secretaryEu);

    expect(rstProtocol($props, 'glaucoma')['released']['evidence']['reviewer'])->toBeNull()
        ->and(json_encode($props['protocols']))->not->toContain('ANA PAULA')
        ->and(json_encode($props['protocols']))->not->toContain('@exemplo.test');
});

it('o estado do protocolo na clínica: ativo, versão nova disponível (com o que mudou) e parado', function () {
    $first = rcfReleased('glaucoma');
    app(ProtocolActivationService::class)->activate($this->entity, (string) $first->protocol_id, (string) $first->id, ['tasks' => ['campimetria' => ['interval' => 8]]], true, $this->doctorUser, $this->doctor);

    $active = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($active['status'])->toBe('active')
        ->and($active['adoption']['version']['version'])->toBe(1)
        ->and($active['adoption']['parameters']['tasks']['campimetria'])->toBe(['interval' => 8, 'enabled' => true])
        ->and($active['adoption']['activated_by'])->not->toBeNull()
        ->and($active['tasks'][1])->toMatchArray(['key' => 'campimetria', 'interval' => 8]) // o formulário mostra o que a clínica escolheu
        ->and($active['diff'])->toBe([]);

    $second = rcfReleaseSecond($first);
    $update = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($update['status'])->toBe('update_available')
        ->and($update['released']['version'])->toBe(2)
        ->and($update['adoption']['version']['version'])->toBe(1)
        ->and($update['diff'])->not->toBe([])
        ->and($update['tasks'][1])->toMatchArray(['key' => 'campimetria', 'interval' => 9]) // o formulário é o da versão NOVA, com o padrão validado dela
        ->and($update['released']['id'])->toBe((string) $second->id);

    app(ProtocolGovernanceService::class)->suspend($second->fresh(), 'Dúvida clínica sobre a campimetria da versão nova.');
    $paused = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    // A v2 saiu de uso; a clínica segue na v1 (substituída, ainda em uso): ativo, sem versão nova liberada.
    expect($paused['status'])->toBe('active');

    app(ProtocolGovernanceService::class)->suspend($first->fresh(), 'Dúvida clínica também sobre a versão anterior.');
    $stopped = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($stopped['status'])->toBe('paused')
        ->and($stopped['adoption']['usable'])->toBeFalse()
        ->and($stopped['adoption']['pause_reason'])->toBe('suspended');
});

it('adoção PARADA com versão nova liberada: a tela mostra o que mudou e o médico troca para ela (não fica preso)', function () {
    $first = rcfReleased('glaucoma');
    app(ProtocolActivationService::class)->activate($this->entity, (string) $first->protocol_id, (string) $first->id, [], true, $this->doctorUser, $this->doctor);
    $second = rcfReleaseSecond($first);

    // A versão ADOTADA (agora substituída) é suspensa; a v2 continua liberada.
    app(ProtocolGovernanceService::class)->suspend($first->fresh(), 'Dúvida clínica sobre a versão que a clínica adotou.');

    $stopped = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($stopped['status'])->toBe('paused')
        ->and($stopped['adoption']['pause_reason'])->toBe('suspended')
        ->and($stopped['released']['id'])->toBe((string) $second->id)
        ->and($stopped['diff'])->not->toBe([]) // o que a clínica vai assumir
        ->and($stopped['tasks'][1])->toMatchArray(['key' => 'campimetria', 'interval' => 9]); // formulário da versão liberada

    rstAs($this, $this->doctorUser, $this->doctorEu)
        ->post(route('panel.recall.settings.protocols.activate', $first->protocol_id), ['version_id' => (string) $second->id, 'acknowledged' => true])
        ->assertSessionHas('success');

    $swapped = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($swapped['status'])->toBe('active')
        ->and($swapped['adoption']['version_id'])->toBe((string) $second->id)
        ->and($swapped['diff'])->toBe([])
        ->and(RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->whereNull('deactivated_at')->count())->toBe(1);
});

it('a prova que falha tira a regra do ar: versão liberada adulterada não é oferecida; adoção parada diz o motivo (integridade, conteúdo diferente)', function () {
    // Adulterada ANTES de qualquer adoção: nada a oferecer, nem formulário.
    crfTamperVersionContent(rcfReleased('glaucoma'));
    $none = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect($none['status'])->toBe('unavailable')
        ->and($none['released'])->toBeNull()
        ->and($none['tasks'])->toBe([]);

    // A clínica já usava e a versão foi adulterada depois: parada por integridade, e a regra adulterada não é oferecida.
    $dmri = rcfReleased('dmri');
    rcfAdopt($this->entity, 'dmri', version: $dmri);
    crfTamperVersionContent($dmri);
    $integrity = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'dmri');

    expect($integrity['status'])->toBe('paused')
        ->and($integrity['adoption']['pause_reason'])->toBe('integrity')
        ->and($integrity['released'])->toBeNull();

    // O conteúdo da versão não é o que a clínica adotou (hash adotado diferente): parada, motivo próprio.
    $retinopathy = rcfReleased('retinopatia_diabetica');
    rcfAdopt($this->entity, 'retinopatia_diabetica', version: $retinopathy)->forceFill(['adopted_content_hash' => '1:' . str_repeat('0', 64)])->save();
    $hash = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'retinopatia_diabetica');

    expect($hash['status'])->toBe('paused')
        ->and($hash['adoption']['pause_reason'])->toBe('hash');
});

it('o curso de tratamento não vira parâmetro na tela: só as tarefas da lista aparecem no formulário', function () {
    $proposal           = ProposedProtocolDrafts::all()['glaucoma'];
    $content            = $proposal['content'];
    $content['tasks'][] = [
        'key'       => 'aplicacao', 'kind' => 'treatment_course', 'exam_key' => null,
        'interval'  => ['unit' => 'weeks', 'default' => 8, 'min' => 4, 'max' => 16],
        'lead_days' => 7, 'grace_days' => 7, 'lost_after_days' => 90, 'starts_after_months' => null, 'recurs' => true, 'required' => true,
    ];
    app(ProtocolCatalogService::class)->createProtocol('glaucoma', $proposal['translations'], $content, $proposal['sort_order']);
    rcfReleased('glaucoma');

    $page = rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma');

    expect(collect($page['tasks'])->pluck('key')->all())->toBe(['consulta', 'campimetria', 'oct_nervo_optico'])
        ->and(collect($page['released']['content']['tasks'])->pluck('key')->all())->toContain('aplicacao'); // a regra mostra tudo; o formulário só o ajustável
});

// ── ativar e desativar (ato do médico) ──────────────────────────────────────

it('o médico ATIVA o protocolo: adoção gravada com o retrato do que ele viu; a clínica é marcada para reavaliação', function () {
    $version = rcfReleased('glaucoma');
    $patient = rcfPatient($this->entity);
    rcfRecord($this->entity, $patient, $this->doctor, now()->subDays(200)->toDateString(), cids: ['H40.2'], signed: true);
    DB::table('recall_dirty_patients')->delete();

    rstAs($this, $this->doctorUser, $this->doctorEu)
        ->from(route('panel.recall.settings.index'))
        ->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), [
            'version_id'   => (string) $version->id,
            'tasks'        => ['consulta' => ['interval' => 3, 'enabled' => true], 'oct_nervo_optico' => ['enabled' => true, 'interval' => 12]],
            'acknowledged' => true,
        ])
        ->assertRedirect(route('panel.recall.settings.index'))
        ->assertSessionHas('success');

    $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->sole();

    expect($adoption->version_id)->toBe((string) $version->id)
        ->and($adoption->parameters['tasks']['consulta'])->toBe(['interval' => 3, 'enabled' => true])
        ->and($adoption->parameters['tasks']['oct_nervo_optico']['enabled'])->toBeTrue()
        ->and($adoption->activated_by)->toBe((string) $this->doctorUser->id)
        ->and($adoption->activated_by_doctor_id)->toBe((string) $this->doctor->id)
        ->and($adoption->activation_snapshot['evidence']['reviewer']['name'])->toBe('DRA. ANA PAULA SOUZA')
        ->and(DB::table('recall_dirty_patients')->where('patient_id', $patient->id)->count())->toBe(1);
});

it('administrador e secretária NÃO ativam nem desativam: é ato clínico (403)', function () {
    $version = rcfReleased('glaucoma');
    $body    = ['version_id' => (string) $version->id, 'acknowledged' => true];

    foreach ([[$this->admin, $this->adminEu], [$this->secretary, $this->secretaryEu], [$this->financial, $this->financialEu]] as [$user, $eu]) {
        rstAs($this, $user, $eu)->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), $body)->assertForbidden();
        rstAs($this, $user, $eu)->post(route('panel.recall.settings.protocols.deactivate', $version->protocol_id), ['reason' => 'Tentando desativar sem ser médico'])->assertForbidden();
    }

    expect(RecallClinicProtocol::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('médico SEM cadastro ativo na clínica também não decide', function () {
    $version = rcfReleased('glaucoma');

    expect(rstPage($this, $this->doctorUser, $this->doctorEu))->toMatchArray(['doctorMissing' => false, 'canDecide' => true]);

    $this->doctor->forceFill(['active' => false])->save();

    expect(rstPage($this, $this->doctorUser, $this->doctorEu))->toMatchArray(['doctorMissing' => true, 'canDecide' => false, 'isDoctor' => true]);

    rstAs($this, $this->doctorUser, $this->doctorEu)
        ->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), ['version_id' => (string) $version->id, 'acknowledged' => true])
        ->assertForbidden();
});

it('ativar exige a confirmação; parâmetro fora da faixa volta no campo certo, com a mensagem traduzida; nada é gravado', function () {
    $version = rcfReleased('glaucoma');
    $as      = fn () => rstAs($this, $this->doctorUser, $this->doctorEu);

    $as()->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), ['version_id' => (string) $version->id])->assertSessionHasErrors('acknowledged');

    $as()->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), [
        'version_id' => (string) $version->id, 'acknowledged' => true,
        'tasks'      => ['consulta' => ['interval' => 2], 'campimetria' => ['enabled' => false]],
    ])->assertSessionHasErrors(['tasks.consulta.interval' => 'Fora da faixa validada.', 'tasks.campimetria.enabled' => 'Esta tarefa é obrigatória.']);

    $as()->post(route('panel.recall.settings.protocols.activate', $version->protocol_id), ['version_id' => '01a00000-0000-7000-8000-000000000000', 'acknowledged' => true])->assertSessionHas('error');

    expect(RecallClinicProtocol::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('desativar: o médico, com o motivo; motivo curto é recusado; protocolo que não está ativo, aviso', function () {
    $version = rcfReleased('glaucoma');
    $as      = fn () => rstAs($this, $this->doctorUser, $this->doctorEu);

    $as()->post(route('panel.recall.settings.protocols.deactivate', $version->protocol_id), ['reason' => 'curto'])->assertSessionHasErrors('reason');
    $as()->post(route('panel.recall.settings.protocols.deactivate', $version->protocol_id), ['reason' => 'Esta clínica não acompanha glaucoma'])->assertSessionHas('error'); // não está ativo

    app(ProtocolActivationService::class)->activate($this->entity, (string) $version->protocol_id, (string) $version->id, [], true, $this->doctorUser, $this->doctor);

    $as()->post(route('panel.recall.settings.protocols.deactivate', $version->protocol_id), ['reason' => 'Esta clínica não acompanha glaucoma'])->assertSessionHas('success');

    $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()->sole();

    expect($adoption->deactivated_at)->not->toBeNull()
        ->and($adoption->deactivation_reason)->toBe('Esta clínica não acompanha glaucoma')
        ->and(rstProtocol(rstPage($this, $this->secretary, $this->secretaryEu), 'glaucoma')['status'])->toBe('available');
});

it('a ativação de uma clínica não aparece na outra', function () {
    $version = rcfReleased('glaucoma');
    app(ProtocolActivationService::class)->activate($this->entity, (string) $version->protocol_id, (string) $version->id, [], true, $this->doctorUser, $this->doctor);

    $other = rcfClinic(recall: true);
    $eu    = EntityUser::query()->findOrFail($other['doctor']->entity_user_id);
    $props = rstPage($this, User::query()->findOrFail($eu->user_id), $eu);

    expect(rstProtocol($props, 'glaucoma')['status'])->toBe('available')
        ->and(rstProtocol($props, 'glaucoma')['adoption'])->toBeNull();
});

// ── retorno pedido pelo médico ──────────────────────────────────────────────

it('o administrador (ou o médico) liga e desliga o retorno pedido pelo médico; a secretária não; a linha nasce na 1ª mudança', function () {
    expect(RecallClinicSettings::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(rstPage($this, $this->secretary, $this->secretaryEu)['general']['doctor_order_enabled'])->toBeTrue();

    rstAs($this, $this->secretary, $this->secretaryEu)->put(route('panel.recall.settings.general'), ['doctor_order_enabled' => false])->assertForbidden();

    $patient = rcfPatient($this->entity);
    rcfRecord($this->entity, $patient, $this->doctor, Carbon::now()->subDays(10)->toDateString(), 60);
    rcfEvaluate($this->entity, $patient, Carbon::now()->toDateString());

    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.general'), ['doctor_order_enabled' => false])->assertSessionHas('success');

    $settings = RecallClinicSettings::query()->withoutGlobalScopes()->sole();

    expect($settings->doctor_order_enabled)->toBeFalse()
        ->and($settings->invite_weekdays)->toBe(RecallClinicSettings::DEFAULT_WEEKDAYS)
        ->and(rstPage($this, $this->admin, $this->adminEu)['general']['doctor_order_enabled'])->toBeFalse();

    // A mudança marca a clínica e a reavaliação encerra a linha do retorno pedido.
    expect(DB::table('recall_dirty_patients')->where('patient_id', $patient->id)->count())->toBe(1);
    rcfEvaluate($this->entity, $patient, Carbon::now()->toDateString());
    expect(PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->sole()->closed_reason)->toBe('doctor_order_disabled');

    rstAs($this, $this->doctorUser, $this->doctorEu)->put(route('panel.recall.settings.general'), ['doctor_order_enabled' => true])->assertSessionHas('success');
    expect($settings->fresh()->doctor_order_enabled)->toBeTrue();

    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.general'), [])->assertSessionHasErrors('doctor_order_enabled');
});

// ── mapeamento de exames ────────────────────────────────────────────────────

it('a tela traz os exames da clínica e os globais, o mapeamento atual e as SUGESTÕES (só globais marcados)', function () {
    $global   = ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA COMPUTADORIZADA', 'canonical_key' => 'visual_field']);
    $own      = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'CAMPO VISUAL 24-2']);
    $inactive = ExamType::factory()->create(['entity_id' => null, 'name' => 'EXAME INATIVO', 'active' => false]);
    $foreign  = ExamType::factory()->create(['entity_id' => rcfClinic()['entity']->id, 'name' => 'EXAME DE OUTRA CLÍNICA']);

    // A sugestão é só do catálogo GLOBAL e ATIVO: chave canônica num tipo inativo ou de outra clínica nunca vaza.
    ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA DESATIVADA', 'canonical_key' => 'visual_field', 'active' => false]);
    ExamType::factory()->create(['entity_id' => rcfClinic()['entity']->id, 'name' => 'CAMPIMETRIA DE OUTRA CLÍNICA', 'canonical_key' => 'visual_field']);

    rcfMapExam($this->entity, 'visual_field', $own);

    $props = rstPage($this, $this->secretary, $this->secretaryEu);
    $names = collect($props['exams']['types'])->pluck('name')->all();

    expect($names)->toContain('CAMPIMETRIA COMPUTADORIZADA', 'CAMPO VISUAL 24-2')
        ->and(array_values(array_intersect($names, ['EXAME INATIVO', 'EXAME DE OUTRA CLÍNICA'])))->toBe([])
        ->and($props['exams']['mappings']['visual_field'])->toBe([(string) $own->id])
        ->and($props['exams']['suggestions']['visual_field'])->toBe([(string) $global->id])
        ->and($props['exams']['suggestions']['oct_macula'])->toBe([])
        ->and(collect($props['exams']['keys'])->pluck('key')->all())->toBe(['visual_field', 'oct_optic_nerve', 'oct_macula', 'retinography', 'corneal_topography'])
        ->and($inactive->active)->toBeFalse()
        ->and($foreign->entity_id)->not->toBe($this->entity->id);
});

it('salvar o mapeamento troca o conjunto inteiro, marca a clínica; tipo de outra clínica, chave desconhecida e secretária são recusados', function () {
    $campimetria = ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA COMPUTADORIZADA']);
    $own         = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'CAMPO VISUAL 24-2']);
    $macula      = ExamType::factory()->create(['entity_id' => null, 'name' => 'OCT MÁCULA']);
    $foreign     = ExamType::factory()->create(['entity_id' => rcfClinic()['entity']->id, 'name' => 'EXAME DE OUTRA CLÍNICA']);

    $patient = rcfPatient($this->entity);
    rcfCondition($this->entity, $patient, 'glaucoma');
    DB::table('recall_dirty_patients')->delete();

    rstAs($this, $this->secretary, $this->secretaryEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id]]])->assertForbidden();

    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $campimetria->id, (string) $own->id], 'oct_macula' => [(string) $macula->id]]])->assertSessionHas('success');

    $rows = RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->get()->groupBy('exam_key')->map(fn ($group) => $group->pluck('exam_type_id')->sort()->values()->all());

    expect($rows['visual_field'])->toBe(collect([(string) $campimetria->id, (string) $own->id])->sort()->values()->all())
        ->and($rows['oct_macula'])->toBe([(string) $macula->id])
        ->and(DB::table('recall_dirty_patients')->where('patient_id', $patient->id)->count())->toBe(1);

    // Trocar o conjunto: o que saiu sai, o que ficou continua (sem recriar).
    $keptId = RecallExamMapping::query()->withoutGlobalScopes()->where('exam_type_id', $own->id)->value('id');
    rstAs($this, $this->doctorUser, $this->doctorEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id]]])->assertSessionHas('success');

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1)
        ->and(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->value('id'))->toBe($keptId);

    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $foreign->id]]])->assertSessionHas('error');
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['inventado' => [(string) $own->id]]])->assertSessionHasErrors('mappings');
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => ['nao-uuid']]])->assertSessionHasErrors();

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('tipo de exame EXCLUÍDO nunca é oferecido nem sugerido; o já mapeado que saiu do catálogo aparece marcado e pode ser tirado', function () {
    $global  = ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA COMPUTADORIZADA', 'canonical_key' => 'visual_field']);
    $deleted = ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA ANTIGA', 'canonical_key' => 'visual_field']);
    $own     = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'CAMPO VISUAL 24-2']);
    $stale   = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'CAMPO VISUAL ANTIGO']);

    rcfMapExam($this->entity, 'visual_field', $own);
    rcfMapExam($this->entity, 'visual_field', $stale);
    $deleted->delete();
    $stale->forceFill(['active' => false])->save();

    $types = collect(rstPage($this, $this->admin, $this->adminEu)['exams']['types'])->keyBy('name');

    expect($types->keys()->all())->toContain('CAMPIMETRIA COMPUTADORIZADA', 'CAMPO VISUAL 24-2', 'CAMPO VISUAL ANTIGO')
        ->and($types->has('CAMPIMETRIA ANTIGA'))->toBeFalse() // excluído: nem na lista nem como sugestão
        ->and($types['CAMPO VISUAL 24-2']['unavailable'])->toBeFalse()
        ->and($types['CAMPO VISUAL ANTIGO']['unavailable'])->toBeTrue() // já mapeado, desativado depois
        ->and(rstPage($this, $this->admin, $this->adminEu)['exams']['suggestions']['visual_field'])->toBe([(string) $global->id]);

    // Salvar o conjunto como está (com o desativado) funciona e não recria nada; trocar para um NOVO desativado, não.
    $keptId = RecallExamMapping::query()->withoutGlobalScopes()->where('exam_type_id', $stale->id)->value('id');
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id, (string) $stale->id]]])->assertSessionHas('success');

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('exam_type_id', $stale->id)->value('id'))->toBe($keptId);

    $other = ExamType::factory()->create(['entity_id' => $this->entity->id, 'name' => 'OUTRO DESATIVADO', 'active' => false]);
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id, (string) $stale->id, (string) $other->id]]])->assertSessionHas('error');
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id, (string) $deleted->id]]])->assertSessionHas('error');

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(2);

    // Tirar o desativado: sai da lista e do mapeamento.
    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $own->id]]])->assertSessionHas('success');

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1)
        ->and(collect(rstPage($this, $this->admin, $this->adminEu)['exams']['types'])->pluck('name')->all())->not->toContain('CAMPO VISUAL ANTIGO');
});

it('o mapeamento de uma clínica nunca aparece nem mexe no de outra', function () {
    $type  = ExamType::factory()->create(['entity_id' => null, 'name' => 'CAMPIMETRIA COMPUTADORIZADA']);
    $other = rcfClinic(recall: true);
    rcfMapExam($other['entity'], 'visual_field', $type);

    expect(rstPage($this, $this->admin, $this->adminEu)['exams']['mappings']['visual_field'])->toBe([]);

    rstAs($this, $this->admin, $this->adminEu)->put(route('panel.recall.settings.exam-mappings'), ['mappings' => ['visual_field' => [(string) $type->id]]])->assertSessionHas('success');

    expect(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $other['entity']->id)->count())->toBe(1)
        ->and(RecallExamMapping::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->count())->toBe(1);
});

it('o item de configuração leva da tela de Retornos à de configuração (rota registrada e protegida pelo plano)', function () {
    expect(route('panel.recall.settings.index', absolute: false))->toBe('/panel/recall/settings')
        ->and(route('panel.recall.settings.protocols.activate', '01a00000-0000-7000-8000-000000000000', absolute: false))->toBe('/panel/recall/settings/protocols/01a00000-0000-7000-8000-000000000000/activate');

    rstAs($this, $this->secretary, $this->secretaryEu)->post('/panel/recall/settings/protocols/nao-e-uuid/activate', [])->assertNotFound();
});
