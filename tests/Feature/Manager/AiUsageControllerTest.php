<?php

use App\Enums\{ClientRule, SaasRule};
use App\Models\{Entity, User};
use App\Support\PanelNavigation;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Support\Facades\DB;

/**
 * Manager → Uso de IA: custo e uso de IA por período, ação, clínica, usuário
 * e provedor/modelo, falhas, execuções (detalhe + CSV). Cenário com valores
 * conhecidos (cotação R$ 5,00; 1 crédito = US$ 0,01):
 *
 *  r1 Alfa/a1  eye_image_analysis  approved 10 cr  openai 0,30 + gemini 0,10
 *  r2 Alfa/a1  record_assist       failed    0 cr  openai falhou + anthropic pulada
 *  r3 Beta/b1  assistant_chat      approved  4 cr  anthropic 0,05
 *  r4 SaaS     medicine_posology   approved  0 cr  openai 0,02 (uso interno)
 *  r0 Alfa/a1  período anterior    approved  5 cr  openai 0,10
 */
function aiUsageSession(Entity $entity, string $rule): array
{
    return [
        'selected_entity_id'        => $entity->id,
        'selected_entity_is_client' => (bool) $entity->is_client,
        'selected_entity_user_rule' => $rule,
    ];
}

/** @param list<array<string, mixed>> $calls */
function aiUsageRun(Entity $entity, User $user, string $workflow, string $status, int $credits, string $at, array $calls = [], array $extra = []): string
{
    $id = (string) Str::uuid();

    DB::table('ai_runs')->insert([
        'id'               => $id,
        'entity_id'        => $entity->id,
        'requested_by'     => $user->id,
        'workflow'         => $workflow,
        'mode'             => 'validated',
        'risk_level'       => 'low',
        'status'           => $status,
        'consumed_credits' => $credits,
        'created_at'       => $at,
        'updated_at'       => $at,
        ...$extra,
    ]);

    foreach ($calls as $i => $call) {
        DB::table('ai_run_provider_calls')->insert([
            'id'            => (string) Str::uuid(),
            'ai_run_id'     => $id,
            'role'          => $i === 0 ? 'generator' : 'reviewer',
            'input_tokens'  => 1000,
            'output_tokens' => 200,
            'latency_ms'    => 1000,
            'created_at'    => Carbon::parse($at)->addSeconds($i + 1),
            'updated_at'    => Carbon::parse($at)->addSeconds($i + 1),
            ...$call,
        ]);
    }

    return $id;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 12:00:00');
    config(['ai.pricing.usd_per_credit' => 0.01]);

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true, 'name' => 'EasyEye']);
    $this->admin = User::factory()->create(['name' => 'Admin SaaS']);
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->alfa = Entity::factory()->create(['is_client' => true, 'active' => true, 'name' => 'Clínica Alfa']);
    $this->beta = Entity::factory()->create(['is_client' => true, 'active' => true, 'name' => '=HYPERLINK("http://x")']);
    $this->a1   = User::factory()->create(['name' => 'Dra. Ana']);
    $this->b1   = User::factory()->create(['name' => 'Dr. Beto']);
    createEntityUser($this->alfa, $this->a1, ClientRule::Doctor->value);
    createEntityUser($this->beta, $this->b1, ClientRule::Doctor->value);

    DB::table('ai_provider_topups')->insert([
        'id'            => (string) Str::uuid(), 'provider' => 'openai', 'amount_usd' => 100, 'amount_brl' => 500,
        'exchange_rate' => 5.0, 'topped_up_at' => '2026-09-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->r1 = aiUsageRun($this->alfa, $this->a1, 'eye_image_analysis', 'approved', 10, '2026-10-02 10:00:00', [
        ['provider' => 'openai', 'model' => 'gpt-4.1', 'status' => 'success', 'raw_cost_usd' => 0.30],
        ['provider' => 'gemini', 'model' => 'gemini-2.5', 'status' => 'success', 'raw_cost_usd' => 0.10, 'latency_ms' => 3000],
    ], [
        // Conteúdo clínico NUNCA pode aparecer na tela/CSV/detalhe.
        'input_summary' => json_encode(['user_prompt' => 'PACIENTE JOSE DA SILVA CPF 123']),
        'final_output'  => 'LAUDO SECRETO DO PACIENTE',
    ]);
    $this->r2 = aiUsageRun($this->alfa, $this->a1, 'record_assist', 'failed', 0, '2026-10-03 09:00:00', [
        ['provider'         => 'openai', 'model' => 'gpt-4.1', 'status' => 'failed', 'raw_cost_usd' => null,
            'error_message' => 'OpenAI request failed [401]: Incorrect API key provided: sk-proj-ABCDEFGH12345678 for joao@clinica.com'],
        ['provider' => 'anthropic', 'model' => 'claude-sonnet', 'status' => 'skipped', 'raw_cost_usd' => null],
    ], ['error_message' => __('ai.run_failed_generic')]);
    $this->r3 = aiUsageRun($this->beta, $this->b1, 'assistant_chat', 'approved', 4, '2026-10-03 15:00:00', [
        ['provider' => 'anthropic', 'model' => 'claude-sonnet', 'status' => 'success', 'raw_cost_usd' => 0.05],
    ]);
    $this->r4 = aiUsageRun($this->saas, $this->admin, 'medicine_posology', 'approved', 0, '2026-10-10 08:00:00', [
        ['provider' => 'openai', 'model' => 'gpt-4.1', 'status' => 'success', 'raw_cost_usd' => 0.02],
    ]);
    $this->r0 = aiUsageRun($this->alfa, $this->a1, 'eye_image_analysis', 'approved', 5, '2026-09-20 10:00:00', [
        ['provider' => 'openai', 'model' => 'gpt-4.1', 'status' => 'success', 'raw_cost_usd' => 0.10],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function asAiUsageUser(?User $user = null, ?Entity $entity = null, string $rule = 'admin')
{
    return test()->actingAs($user ?? test()->admin)->withSession(aiUsageSession($entity ?? test()->saas, $rule));
}

/** @return array<string, mixed> */
function aiUsageProps(array $query = []): array
{
    return asAiUsageUser()->get(route('manager.ai-usage.index', $query))->assertOk()->viewData('page')['props'];
}

describe('indicadores', function () {
    it('custo em US$ e R$, execuções, falhas, créditos, receita e margem do período', function () {
        $kpi = aiUsageProps()['kpis']['current'];

        expect($kpi['runs'])->toBe(4)
            ->and($kpi['failed'])->toBe(1)
            ->and($kpi['failure_rate'])->toBe(25.0)
            ->and($kpi['calls'])->toBe(6)
            ->and($kpi['failed_calls'])->toBe(1)
            ->and($kpi['cost_usd'])->toBe(0.47)
            ->and($kpi['cost_brl'])->toBe(2.35)
            ->and($kpi['credits'])->toBe(14)
            ->and($kpi['revenue_brl'])->toBe(0.7)
            ->and($kpi['margin_brl'])->toBe(-1.65)
            ->and($kpi['entities'])->toBe(3)
            ->and($kpi['users'])->toBe(3)
            ->and($kpi['avg_cost_brl'])->toBe(0.5875);
    });

    it('compara com o período anterior de mesma duração', function () {
        $kpis = aiUsageProps()['kpis'];

        expect($kpis['previous']['runs'])->toBe(1)
            ->and($kpis['previous']['cost_brl'])->toBe(0.5)
            ->and($kpis['delta']['runs'])->toBe(300.0)
            ->and($kpis['delta']['cost_brl'])->toBe(370.0)
            ->and($kpis['delta']['failure_rate_pp'])->toBe(25.0);
    });

    it('cotação vem da última recarga; sem recarga usa a estimada e avisa', function () {
        expect(aiUsageProps()['rate'])->toMatchArray(['rate' => 5.0, 'is_fallback' => false, 'topped_up_at' => '2026-09-01']);

        DB::table('ai_provider_topups')->delete();

        expect(aiUsageProps()['rate'])->toMatchArray(['rate' => 5.5, 'is_fallback' => true]);
    });
});

describe('filtros', function () {
    it('por clínica, usuário, ação e situação', function () {
        expect(aiUsageProps(['entity_id' => $this->alfa->id])['kpis']['current'])->toMatchArray(['runs' => 2, 'cost_brl' => 2.0])
            ->and(aiUsageProps(['user_id' => $this->b1->id])['kpis']['current'])->toMatchArray(['runs' => 1, 'cost_brl' => 0.25])
            ->and(aiUsageProps(['workflow' => 'record_assist'])['kpis']['current'])->toMatchArray(['runs' => 1, 'failed' => 1, 'cost_brl' => 0.0])
            ->and(aiUsageProps(['status' => 'failed'])['kpis']['current'])->toMatchArray(['runs' => 1]);
    });

    it('por provedor: execuções que usaram o provedor e só o custo dele', function () {
        $kpi = aiUsageProps(['provider' => 'anthropic'])['kpis']['current'];

        // r2 (chamada pulada) e r3; custo só da chamada anthropic de r3.
        expect($kpi)->toMatchArray(['runs' => 2, 'calls' => 2, 'cost_usd' => 0.05, 'cost_brl' => 0.25]);
    });

    it('período personalizado e nome do usuário filtrado', function () {
        $props = aiUsageProps(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'user_id' => $this->a1->id]);

        expect($props['kpis']['current'])->toMatchArray(['runs' => 1, 'cost_brl' => 0.5])
            ->and($props['filters'])->toMatchArray(['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'user_name' => $this->a1->fresh()->name]);
    });

    it('[VALIDAÇÃO] período inválido, longo demais, uuid e enums inválidos não viram erro 500', function (array $query, string $field) {
        asAiUsageUser()->get(route('manager.ai-usage.index', $query))->assertSessionHasErrors($field);
    })->with([
        'custom sem datas'      => [['preset' => 'custom'], 'from'],
        'fim antes do início'   => [['preset' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01'], 'to'],
        'mais de 2 anos'        => [['preset' => 'custom', 'from' => '2023-01-01', 'to' => '2026-01-01'], 'to'],
        'preset desconhecido'   => [['preset' => 'forever'], 'preset'],
        'clínica não-uuid'      => [['entity_id' => "1' OR '1'='1"], 'entity_id'],
        'provedor desconhecido' => [['provider' => 'deepseek'], 'provider'],
        'situação desconhecida' => [['status' => 'hacked'], 'status'],
        'ordenação fora'        => [['sort' => 'raw_cost_usd; DROP TABLE ai_runs'], 'sort'],
    ]);
});

describe('série e rankings', function () {
    it('série diária com dias sem uso zerados; 12 meses agrupa por mês', function () {
        $series = aiUsageProps()['series'];
        $points = collect($series['points'])->keyBy('key');

        expect($series['granularity'])->toBe('day')
            ->and($points)->toHaveCount(15)
            ->and($points['2026-10-01'])->toMatchArray(['runs' => 0, 'cost_brl' => 0.0])
            ->and($points['2026-10-02'])->toMatchArray(['runs' => 1, 'cost_brl' => 2.0])
            ->and($points['2026-10-03'])->toMatchArray(['runs' => 2, 'failed' => 1, 'cost_brl' => 0.25]);

        $year = aiUsageProps(['preset' => '12m'])['series'];
        expect($year['granularity'])->toBe('month')
            ->and(collect($year['points'])->pluck('key')->first())->toBe('2025-10')
            ->and(collect($year['points'])->firstWhere('key', '2026-09'))->toMatchArray(['runs' => 1]);
    });

    it('por ação: maior custo primeiro, com falhas e rótulo traduzido', function () {
        $rows = aiUsageProps()['byWorkflow'];

        expect(array_column($rows, 'workflow'))->toBe(['eye_image_analysis', 'assistant_chat', 'medicine_posology', 'record_assist'])
            ->and($rows[0])->toMatchArray(['label' => __('ai.workflow_eye_image_analysis'), 'runs' => 1, 'cost_brl' => 2.0, 'credits' => 10])
            ->and($rows[3])->toMatchArray(['failed' => 1, 'failure_rate' => 100.0, 'cost_brl' => 0.0]);
    });

    it('por clínica: uso interno da plataforma separado, receita e margem por clínica', function () {
        $byEntity = aiUsageProps()['byEntity'];
        $rows     = collect($byEntity['rows'])->keyBy('entity_id');

        expect($byEntity['total'])->toBe(3)
            ->and($rows[$this->alfa->id])->toMatchArray(['runs' => 2, 'failed' => 1, 'cost_brl' => 2.0, 'revenue_brl' => 0.5, 'is_internal' => false])
            ->and($rows[$this->saas->id])->toMatchArray(['cost_brl' => 0.1, 'revenue_brl' => 0.0, 'is_internal' => true]);
    });

    it('por usuário (em cada clínica)', function () {
        $rows = collect(aiUsageProps()['byUser']['rows'])->keyBy('user_id');

        expect($rows[$this->a1->id])->toMatchArray(['name' => $this->a1->fresh()->name, 'entity_name' => $this->alfa->fresh()->name, 'runs' => 2, 'cost_brl' => 2.0])
            ->and($rows[$this->admin->id])->toMatchArray(['is_internal' => true, 'cost_brl' => 0.1]);
    });

    it('por provedor e modelo: chamadas, falhas, puladas, custo e latência', function () {
        $rows = collect(aiUsageProps()['byProvider'])->keyBy('provider');

        expect($rows['openai'])->toMatchArray(['model' => 'gpt-4.1', 'calls' => 3, 'failed_calls' => 1, 'call_failure_rate' => 33.3, 'cost_brl' => 1.6, 'avg_latency_ms' => 1000])
            ->and($rows['anthropic'])->toMatchArray(['calls' => 2, 'skipped_calls' => 1, 'cost_brl' => 0.25])
            ->and($rows['gemini'])->toMatchArray(['calls' => 1, 'avg_latency_ms' => 3000, 'provider_label' => __('ai.providers.gemini')]);
    });
});

describe('execuções', function () {
    it('lista paginada, mais recentes primeiro ou por custo, com provedores usados', function () {
        $byDate = aiUsageProps()['runs'];
        expect(array_column($byDate['data'], 'id'))->toBe([$this->r4, $this->r3, $this->r2, $this->r1])
            ->and($byDate['total'])->toBe(4);

        $byCost = aiUsageProps(['sort' => 'cost', 'direction' => 'desc'])['runs']['data'];
        expect(array_column($byCost, 'id'))->toBe([$this->r1, $this->r3, $this->r4, $this->r2])
            ->and($byCost[0])->toMatchArray([
                'entity_name' => $this->alfa->fresh()->name, 'user_name' => $this->a1->fresh()->name, 'calls' => 2, 'cost_brl' => 2.0,
                'providers'   => [__('ai.providers.gemini'), __('ai.providers.openai')],
            ]);
    });

    it('detalhe: metadados e chamadas, com erro do provedor saneado (sem chave nem e-mail)', function () {
        $detail = asAiUsageUser()->getJson(route('manager.ai-usage.runs.show', $this->r2))->assertOk()->json('data');

        expect($detail)->toMatchArray(['workflow' => 'record_assist', 'status' => 'failed', 'entity_name' => $this->alfa->fresh()->name, 'error' => __('ai.run_failed_generic')])
            ->and($detail['calls'])->toHaveCount(2)
            ->and($detail['calls'][0]['error'])->toContain('[REDACTED:KEY]')->not->toContain('sk-proj-ABCDEFGH12345678')->not->toContain('joao@clinica.com')
            ->and($detail['calls'][1])->toMatchArray(['status' => 'skipped', 'error' => null]);
    });

    it('detalhe inexistente ou id inválido: 404', function () {
        asAiUsageUser()->getJson(route('manager.ai-usage.runs.show', (string) Str::uuid()))->assertNotFound();
        asAiUsageUser()->getJson('/panel/manager/ai-usage/runs/nao-e-uuid')->assertNotFound();
    });

    it('[LGPD] tela, detalhe e CSV nunca trazem prompt nem resposta da IA', function () {
        $page   = asAiUsageUser()->get(route('manager.ai-usage.index'))->getContent();
        $detail = asAiUsageUser()->getJson(route('manager.ai-usage.runs.show', $this->r1))->getContent();
        $csv    = asAiUsageUser()->get(route('manager.ai-usage.export'))->streamedContent();

        foreach ([$page, $detail, $csv] as $content) {
            expect($content)->not->toContain('JOSE DA SILVA')->not->toContain('LAUDO SECRETO');
        }
    });
});

describe('exportação CSV', function () {
    it('todas as execuções do recorte, com BOM, cabeçalho traduzido e números no formato do idioma', function () {
        $response = asAiUsageUser()->get(route('manager.ai-usage.export', ['entity_id' => $this->alfa->id]))->assertOk();
        $csv      = $response->streamedContent();
        $lines    = array_values(array_filter(explode("\n", trim($csv))));

        expect($response->headers->get('content-disposition'))->toContain('.csv')
            ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
            ->and($lines)->toHaveCount(3) // cabeçalho + r2 + r1
            ->and($lines[0])->toContain(__('manager_ai_usage.export.cost_brl'))
            ->and($lines[2])->toContain('0,400000')   // US$ com 6 casas
            ->and($lines[2])->toContain('2,00')       // R$
            ->and($lines[2])->toContain(';2;0;');     // chamadas sem casas decimais
    });

    it('[SEGURANÇA] nome que começa com = é neutralizado (CSV injection)', function () {
        $csv = asAiUsageUser()->get(route('manager.ai-usage.export', ['entity_id' => $this->beta->id]))->streamedContent();

        expect($csv)->toContain("'=HYPERLINK");
    });
});

describe('acesso', function () {
    it('dono do SaaS (mesmo sem papel admin) acessa', function () {
        $owner = User::factory()->create();
        createEntityUser($this->saas, $owner, SaasRule::Financial->value, isOwner: true);

        asAiUsageUser($owner, $this->saas, SaasRule::Financial->value)->get(route('manager.ai-usage.index'))->assertOk();
    });

    it('[SEGURANÇA] financeiro/suporte do SaaS sem ser dono não vê tela, detalhe nem CSV', function (string $rule) {
        $staff = User::factory()->create();
        createEntityUser($this->saas, $staff, $rule);

        asAiUsageUser($staff, $this->saas, $rule)->get(route('manager.ai-usage.index'))->assertForbidden();
        asAiUsageUser($staff, $this->saas, $rule)->getJson(route('manager.ai-usage.runs.show', $this->r1))->assertForbidden();
        asAiUsageUser($staff, $this->saas, $rule)->get(route('manager.ai-usage.export'))->assertForbidden();
    })->with([SaasRule::Financial->value, SaasRule::Support->value]);

    it('[SEGURANÇA] usuário de clínica não acessa', function () {
        asAiUsageUser($this->a1, $this->alfa, ClientRule::Doctor->value)->get(route('manager.ai-usage.index'))->assertRedirect();
        asAiUsageUser($this->a1, $this->alfa, ClientRule::Doctor->value)->getJson(route('manager.ai-usage.runs.show', $this->r1))->assertForbidden();
    });

    it('[AUDITORIA] leitura da tela, do detalhe e a exportação ficam em audit_logs', function () {
        asAiUsageUser()->get(route('manager.ai-usage.index', ['provider' => 'openai']))->assertOk();
        asAiUsageUser()->getJson(route('manager.ai-usage.runs.show', $this->r1))->assertOk();
        asAiUsageUser()->get(route('manager.ai-usage.export'))->assertOk()->streamedContent();

        $logs = DB::table('audit_logs')->where('user_id', $this->admin->id)->where('event', 'access')->get()->keyBy('route_name');

        expect($logs->keys()->all())->toContain('manager.ai-usage.index', 'manager.ai-usage.runs.show', 'manager.ai-usage.export')
            ->and(json_decode($logs['manager.ai-usage.index']->new_values, true)['url'])->toContain('provider=openai')
            ->and($logs['manager.ai-usage.runs.show'])->toMatchArray(['auditable_type' => 'ai_run', 'auditable_id' => $this->r1]);
    });

    it('rankings trazem custo médio por execução', function () {
        $props = aiUsageProps();

        expect(collect($props['byEntity']['rows'])->firstWhere('entity_id', $this->alfa->id)['avg_cost_brl'])->toBe(1.0)
            ->and(collect($props['byUser']['rows'])->firstWhere('user_id', $this->b1->id)['avg_cost_brl'])->toBe(0.25);
    });

    it('menu: grupo IA com submenus, cada um só pra quem passa no Gate da tela', function (string $rule, bool $owner, array $routes) {
        $user = User::factory()->create();
        createEntityUser($this->saas, $user, $rule, isOwner: $owner);

        test()->actingAs($user);
        session(aiUsageSession($this->saas, $rule));

        $nav   = collect(PanelNavigation::build());
        $group = $nav->firstWhere('key', 'ai');

        expect($group)->not->toBeNull()
            ->and($group['label'])->toBe(__('actions.sidemenu.ai_group'))
            ->and(array_column($group['children'], 'route'))->toBe($routes)
            // Itens antigos soltos não ficam duplicados fora do grupo.
            ->and($nav->pluck('route')->filter()->intersect(['manager.ai-usage.index', 'manager.ai-providers.index', 'manager.ai-credit-purchases.index'])->all())->toBe([]);

        // Todo link que aparece abre de verdade pra esse papel (nenhum 403 escondido).
        foreach ($routes as $route) {
            asAiUsageUser($user, $this->saas, $rule)->get(route($route))->assertOk();
        }
    })->with([
        'admin'                   => [SaasRule::Admin->value, false, ['manager.ai-usage.index', 'manager.ai-credit-purchases.index', 'manager.ai-providers.index']],
        'dono (financeiro)'       => [SaasRule::Financial->value, true, ['manager.ai-usage.index', 'manager.ai-credit-purchases.index']],
        'financeiro sem ser dono' => [SaasRule::Financial->value, false, ['manager.ai-credit-purchases.index']],
        'suporte'                 => [SaasRule::Support->value, false, ['manager.ai-credit-purchases.index']],
    ]);

    it('menu: rótulo do submenu de uso é próprio (não reaproveita o do assistente de IA da clínica)', function () {
        test()->actingAs($this->admin);
        session(aiUsageSession($this->saas, SaasRule::Admin->value));

        $usage = collect(collect(PanelNavigation::build())->firstWhere('key', 'ai')['children'])->firstWhere('route', 'manager.ai-usage.index');

        expect($usage['label'])->toBe(__('actions.sidemenu.ai_menu_usage'))
            ->and($usage['label'])->not->toBe(__('actions.sidemenu.ai_usage'));
    });
});
