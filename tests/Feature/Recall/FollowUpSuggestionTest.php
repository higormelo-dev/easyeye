<?php

declare(strict_types=1);

/**
 * A sugestão do campo "Retorno" do prontuário (Fase 6): o intervalo da consulta de retorno que a CLÍNICA escolheu
 * para cada condição crônica ATIVA do paciente, em dias de calendário (mês sem overflow), só para o médico e só com
 * protocolo em uso; e até quantos dias o retorno pedido vale na lista de retornos (o teto validado do protocolo).
 */

use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus};
use App\Domains\Recall\Models\PatientCondition;
use App\Domains\Recall\Services\{FollowUpSuggestionService, ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\ProposedProtocolDrafts;
use App\Enums\{ClientRule, Eye};
use App\Models\{EntityUser, User};
use App\Services\FeatureGateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->doctorEu   = EntityUser::query()->findOrFail($this->doctor->entity_user_id);
    $this->doctorUser = User::query()->findOrFail($this->doctorEu->user_id);
    $this->patient    = rcfPatient($this->entity);
    $this->service    = app(FollowUpSuggestionService::class);
    $this->on         = CarbonImmutable::parse('2026-02-01');
});

function fuSuggest(object $test, ?object $patient = null, ?CarbonImmutable $on = null, ?object $entity = null): array
{
    return $test->service->forPatient((string) ($entity ?? $test->entity)->id, (string) ($patient ?? $test->patient)->id, $on ?? $test->on);
}

/** Protocolo de rascunho padrão com o conteúdo alterado (criado ANTES de liberar, como o produto faz). */
function fuProtocol(string $key, callable $change): void
{
    $proposal = ProposedProtocolDrafts::all()[$key];
    $content  = $change($proposal['content']);

    app(ProtocolCatalogService::class)->createProtocol($key, $proposal['translations'], $content, $proposal['sort_order']);
}

// ── o serviço ───────────────────────────────────────────────────────────────

it('uma sugestão por condição ativa, em dias de calendário, do retorno mais curto ao mais longo; o teto é o menor dos máximos validados', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfAdopt($this->entity, 'dmri');
    rcfCondition($this->entity, $this->patient, 'dmri');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    $result = fuSuggest($this);

    expect(collect($result['suggestions'])->pluck('protocol_key')->all())->toBe(['glaucoma', 'dmri']) // 4 meses antes de 6
        ->and($result['suggestions'][0])->toMatchArray([
            'protocol_key' => 'glaucoma',
            'title'        => 'Glaucoma',
            'eye'          => 'both',
            'interval'     => 4,
            'unit'         => 'months',
            'unit_label'   => 'meses',
            'days'         => 120, // 01/02 + 4 meses = 01/06
        ])
        ->and($result['suggestions'][1])->toMatchArray(['protocol_key' => 'dmri', 'interval' => 6, 'days' => 181]) // 01/02 + 6 meses = 01/08
        // Glaucoma: no máximo 6 meses (181 dias); DMRI: 12 meses (365). Vale o menor.
        ->and($result['max_days'])->toBe(181);
});

it('vale o intervalo que a CLÍNICA escolheu (dentro da faixa), não o padrão validado', function () {
    rcfAdopt($this->entity, 'glaucoma', ['consulta' => ['interval' => 3]]);
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    $result = fuSuggest($this);

    expect($result['suggestions'][0])->toMatchArray(['interval' => 3, 'days' => 89]) // 01/02 + 3 meses = 01/05
        ->and($result['max_days'])->toBe(181); // o teto continua sendo o MÁXIMO validado (6 meses), não o escolhido
});

it('mês sem overflow: 31/08 + 6 meses é 28/02, não 03/03', function () {
    rcfAdopt($this->entity, 'glaucoma', ['consulta' => ['interval' => 6]]);
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    expect(fuSuggest($this, on: CarbonImmutable::parse('2025-08-31'))['suggestions'][0]['days'])->toBe(181); // 31/08/2025 → 28/02/2026
});

it('a data da consulta é a âncora: a mesma regra dá dias diferentes em datas diferentes (a hora não conta)', function () {
    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    expect(fuSuggest($this, on: CarbonImmutable::parse('2026-02-01 23:59:00'))['suggestions'][0]['days'])->toBe(120)
        ->and(fuSuggest($this, on: CarbonImmutable::parse('2026-06-01'))['suggestions'][0]['days'])->toBe(122); // 01/06 + 4 meses = 01/10
});

it('só condição ATIVA do paciente numa clínica que usa o protocolo: sugerida, descartada, com alta, de outro paciente ou de outra clínica não contam', function () {
    rcfAdopt($this->entity, 'glaucoma');

    foreach ([ConditionStatus::Suggested, ConditionStatus::Rejected, ConditionStatus::Discharged] as $status) {
        rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2026-01-15', $status);
    }

    rcfCondition($this->entity, rcfPatient($this->entity), 'glaucoma'); // outro paciente da clínica

    // Linha de OUTRA clínica com o mesmo id de paciente (dado sintético).
    PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => rcfClinic(recall: true)['entity']->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'glaucoma', 'eye' => 'both',
        'status'    => ConditionStatus::Active->value, 'origin' => ConditionOrigin::Manual->value,
    ]);

    expect(fuSuggest($this))->toBe(['suggestions' => [], 'max_days' => null]);

    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Right); // a ativa: agora sim

    expect(fuSuggest($this)['suggestions'])->toHaveCount(1)
        ->and(fuSuggest($this)['suggestions'][0]['eye'])->toBe('right');
});

it('protocolo que a clínica não usa, desativou ou está PARADO (versão suspensa) não sugere nada', function () {
    $adoption = rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'dmri'); // protocolo nunca adotado

    expect(collect(fuSuggest($this)['suggestions'])->pluck('protocol_key')->all())->toBe(['glaucoma']);

    app(ProtocolGovernanceService::class)->suspend($adoption->version->fresh(), 'Dúvida clínica sobre o intervalo da campimetria.');
    expect(fuSuggest($this))->toBe(['suggestions' => [], 'max_days' => null]);

    app(ProtocolGovernanceService::class)->resume($adoption->version->fresh());
    expect(fuSuggest($this)['suggestions'])->toHaveCount(1);

    $adoption->forceFill(['deactivated_at' => now()])->save();
    expect(fuSuggest($this))->toBe(['suggestions' => [], 'max_days' => null]);
});

it('sem a feature no plano: nada (o serviço também respeita o plano)', function () {
    $other = rcfClinic(); // sem has_patient_recall
    $own   = rcfPatient($other['entity']);

    rcfAdopt($other['entity'], 'glaucoma');
    rcfCondition($other['entity'], $own, 'glaucoma');

    expect(fuSuggest($this, $own, entity: $other['entity']))->toBe(['suggestions' => [], 'max_days' => null]);
});

it('protocolo sem teto (cap_to_max desligado) sugere, mas não limita o pedido; com teto, o limite aparece', function () {
    fuProtocol('glaucoma', function (array $content): array {
        $content['doctor_order']['cap_to_max'] = false;

        return $content;
    });

    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    $free = fuSuggest($this);
    expect($free['suggestions'])->toHaveCount(1)
        ->and($free['max_days'])->toBeNull();
});

it('a consulta é achada pelo TIPO da tarefa, não pela posição: exame antes dela na lista não atrapalha', function () {
    fuProtocol('glaucoma', function (array $content): array {
        $content['tasks'] = array_values(array_reverse($content['tasks'])); // exames primeiro, consulta por último

        return $content;
    });

    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    expect(fuSuggest($this)['suggestions'][0])->toMatchArray(['interval' => 4, 'days' => 120]); // a consulta (4 meses), não a campimetria (12)
});

it('consulta de retorno opcional: só sugere se a clínica a ligou; unidade no singular com 1', function () {
    fuProtocol('glaucoma', function (array $content): array {
        foreach ($content['tasks'] as $index => $task) {
            if ($task['kind'] === 'consultation') {
                $content['tasks'][$index]['required']            = false;
                $content['tasks'][$index]['interval']['min']     = 1;
                $content['tasks'][$index]['interval']['default'] = 1;
            }
        }

        return $content;
    });

    $adoption = rcfAdopt($this->entity, 'glaucoma', ['consulta' => ['enabled' => false]]);
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    expect(fuSuggest($this)['suggestions'])->toBe([]); // desligada pela clínica

    $adoption->forceFill(['parameters' => ['tasks' => ['consulta' => ['interval' => 1, 'enabled' => true]]]])->save();

    expect(fuSuggest($this)['suggestions'][0])->toMatchArray(['interval' => 1, 'unit_label' => 'mês', 'days' => 28]); // 01/02 → 01/03
});

// ── a tela do prontuário ────────────────────────────────────────────────────

function fuPage(object $test, User $user, EntityUser $eu, string $route = 'create', ?object $record = null): ?array
{
    $args = $record === null ? [$test->patient] : [$test->patient, $record];

    $response = $test->actingAs($user)->withSession(panelSession($eu))->get(route("panel.patients.medicalrecords.{$route}", $args));

    // Perfil que nem abre a página (403) não recebe nada: devolve a marca em vez de falhar, o teste diz o que esperar.
    return $response->status() === 200 ? ($response->viewData('page')['props']['followUp'] ?? null) : ['denied' => $response->status()];
}

it('prontuário NOVO: o médico recebe as sugestões (a partir de hoje) com os textos; sem condição ativa, nada', function () {
    $this->travelTo('2026-02-01 10:00:00');

    expect(fuPage($this, $this->doctorUser, $this->doctorEu))->toBeNull(); // sem protocolo nem condição

    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    $props = fuPage($this, $this->doctorUser, $this->doctorEu);

    expect($props['suggestions'][0])->toMatchArray(['protocol_key' => 'glaucoma', 'days' => 120])
        ->and($props['max_days'])->toBe(181)
        ->and($props['t']['title'])->toBe('Sugestão dos protocolos de acompanhamento')
        ->and(array_keys($props))->toEqualCanonicalizing(['suggestions', 'max_days', 't']);
});

it('prontuário JÁ GRAVADO: a âncora é a data da consulta (nunca no futuro), não a de hoje', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    $record = rcfRecord($this->entity, $this->patient, $this->doctor, '2025-12-01');
    expect(fuPage($this, $this->doctorUser, $this->doctorEu, 'edit', $record)['suggestions'][0]['days'])->toBe(121); // 01/12 + 4 meses = 01/04

    // Consulta registrada com data no futuro (agendamento adiante): vale hoje, nunca uma data que ainda não chegou.
    $future = rcfRecord($this->entity, $this->patient, $this->doctor, '2026-03-01');
    expect(fuPage($this, $this->doctorUser, $this->doctorEu, 'edit', $future)['suggestions'][0]['days'])->toBe(120);
});

it('só o MÉDICO com cadastro ativo recebe a sugestão: admin e secretária (o mesmo prontuário aberto) e médico sem cadastro ativo, não; plano sem a feature, não', function () {
    $this->travelTo('2026-02-01 10:00:00');

    rcfAdopt($this->entity, 'glaucoma');
    rcfCondition($this->entity, $this->patient, 'glaucoma');

    // Só o médico abre o prontuário: os outros perfis levam 403 antes de qualquer sugestão.
    foreach (['secretary' => ClientRule::Secretary, 'admin' => ClientRule::Admin] as $rule) {
        $user = User::factory()->create();
        $eu   = createEntityUser($this->entity, $user, $rule->value);

        expect(fuPage($this, $user, $eu))->toBe(['denied' => 403], $rule->value);
    }

    // Médico sem cadastro ativo abre a página (com o aviso), mas não recebe a sugestão.
    $this->doctor->forceFill(['active' => false])->save();
    expect(fuPage($this, $this->doctorUser, $this->doctorEu))->toBeNull();
    $this->doctor->forceFill(['active' => true])->save();

    // O plano perde a feature: o médico segue abrindo o prontuário, sem a sugestão.
    DB::table('plan_features')->where('feature', 'has_patient_recall')->update(['value' => '0']);
    app(FeatureGateService::class)->forgetCache((string) $this->entity->id);

    expect(fuPage($this, $this->doctorUser, $this->doctorEu))->toBeNull();
});
