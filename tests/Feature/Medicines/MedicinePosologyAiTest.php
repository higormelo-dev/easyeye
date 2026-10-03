<?php

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings};
use App\Domains\AI\Services\AiRunExecutionService;
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunStatus};
use App\Enums\{MedicineSource, SaasRule};
use App\Models\{Entity, Medicine, User};
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Manager → Medicamentos: "Gerar com IA" sugere a posologia padrão e só
 * PREENCHE o formulário. Run de plataforma (entity SaaS, sem créditos de
 * clínica), prompt sempre do servidor, saída tratada como dado não confiável.
 */
class PosologyFakeProvider implements AiProviderInterface
{
    /** @var list<AiRequestData> */
    public array $requests = [];

    public function __construct(private AiProvider $code, private mixed $reply)
    {
    }

    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $this->requests[] = $request;

        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        return new AiProviderResponseData(
            provider: $this->code,
            model: 'fake-model',
            content: (string) $this->reply,
            usage: new AiUsageData(inputTokens: 100, outputTokens: 50, rawCostUsd: 0.0001),
            latencyMs: 5,
        );
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function provider(): AiProvider
    {
        return $this->code;
    }
}

/** Todos os provedores respondem o mesmo (gerador e revisor no modo Validado). */
function fakePosologyAi(mixed $reply): PosologyFakeProvider
{
    $providers = [];

    foreach (AiProvider::cases() as $case) {
        $providers[$case->value] = new PosologyFakeProvider($case, $reply);
    }

    app()->instance(AiProviderManager::class, new AiProviderManager($providers, app(AiProviderSettings::class)));

    return $providers[AiProvider::OpenAI->value];
}

function posologyJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'dosage'       => '1 gota no olho afetado',
        'frequency'    => 'de 6/6h',
        'duration'     => '7 dias',
        'instructions' => 'Agitar antes de usar.',
        'note'         => '',
    ], $overrides), JSON_UNESCAPED_UNICODE);
}

beforeEach(function () {
    // Padrão: UMA IA configurada (o botão gera direto). Os cenários com
    // várias IAs configuram a lista no próprio teste.
    app(AiProviderSettings::class)->setEnabledCodes([AiProvider::OpenAI->value]);

    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, SaasRule::Admin->value);

    $this->cmed = Medicine::withoutGlobalScopes()->create([
        'name'              => 'PREDOPTIC',
        'active_ingredient' => 'acetato de prednisolona',
        'concentration'     => '10 MG/ML',
        'source'            => MedicineSource::Cmed,
        'source_code'       => '500000000000001',
        'ean'               => '7890000000011',
        'is_ophthalmic'     => true,
        'active'            => true,
    ]);
});

function asPosologyAdmin(string $rule = 'admin')
{
    return test()->actingAs(test()->admin)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule,
    ]);
}

it('item da CMED: sugere pelos dados do banco, não grava no medicamento e registra run de plataforma', function () {
    $provider = fakePosologyAi(posologyJson());

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'name' => 'NOME FORJADO'])
        ->assertOk()
        ->assertJsonPath('suggestion.dosage', '1 gota no olho afetado')
        ->assertJsonPath('suggestion.frequency', 'de 6/6h')
        ->assertJsonPath('suggestion.duration', '7 dias')
        ->assertJsonPath('suggestion.instructions', 'Agitar antes de usar.');

    // Só preenche o formulário: o medicamento continua sem posologia.
    expect($this->cmed->fresh()->dosage)->toBeNull();

    $request = $provider->requests[0];
    expect($request->workflow)->toBe('medicine_posology')
        ->and($request->expectsJson)->toBeTrue()
        ->and($request->context['medicamento']['nome'])->toBe('PREDOPTIC')
        ->and($request->context['medicamento']['principio_ativo'])->toBe('acetato de prednisolona')
        // EAN/registro não vão pro provedor (desnecessários).
        ->and(json_encode($request->context))->not->toContain('7890000000011')
        // Prompt sempre do servidor, com o preâmbulo de segurança.
        ->and($request->systemPrompt)->toContain(__('ai.security_preamble'))
        ->and($request->systemPrompt)->toContain('POSOLOGIA SUGERIDA PADRÃO');

    $run = AiRun::withoutGlobalScopes()->sole();
    expect((string) $run->entity_id)->toBe((string) $this->saas->id)
        ->and((string) $run->requested_by)->toBe((string) $this->admin->id)
        ->and($run->workflow)->toBe('medicine_posology')
        ->and($run->status)->toBe(AiRunStatus::Approved)
        ->and((int) $run->reserved_credits)->toBe(0)
        ->and((int) $run->consumed_credits)->toBe(0);
});

it('cadastro novo: sugere a partir dos campos digitados', function () {
    $provider = fakePosologyAi(posologyJson(['dosage' => '1 comprimido', 'frequency' => '1x ao dia']));

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['name' => 'ACETAZOLAMIDA', 'concentration' => '250 MG'])
        ->assertOk()
        ->assertJsonPath('suggestion.dosage', '1 comprimido');

    expect($provider->requests[0]->context['medicamento'])->toMatchArray(['nome' => 'ACETAZOLAMIDA', 'concentracao' => '250 MG']);
});

it('aceita JSON cercado por markdown ou texto', function () {
    fakePosologyAi("Claro! Segue:\n```json\n" . posologyJson() . "\n```");

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertOk()
        ->assertJsonPath('suggestion.frequency', 'de 6/6h');
});

it('[SEGURANÇA] saída do modelo vira texto plano e respeita o tamanho das colunas', function () {
    fakePosologyAi(posologyJson([
        'dosage'       => '<img src=x onerror=alert(1)>1 gota',
        'frequency'    => str_repeat('a', 400),
        'instructions' => "Agitar antes.\nGuardar em geladeira.",
    ]));

    $suggestion = asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertOk()
        ->json('suggestion');

    expect($suggestion['dosage'])->toBe('1 gota')
        ->and(mb_strlen($suggestion['frequency']))->toBe(255)
        ->and($suggestion['instructions'])->toBe("Agitar antes.\nGuardar em geladeira.");
});

it('IA sem posologia segura: 422 com a observação dela', function () {
    fakePosologyAi(posologyJson(['dosage' => '', 'frequency' => '', 'duration' => '', 'instructions' => '', 'note' => 'Dose varia conforme a indicação.']));

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Dose varia conforme a indicação.');
});

it('resposta que não é JSON: 422 pedindo preenchimento manual', function () {
    fakePosologyAi('Não sei.');

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertStatus(422)
        ->assertJsonPath('message', __('manager_medicines.ai_no_suggestion'));
});

it('falha do provedor: 422 com mensagem genérica (sem detalhe técnico) e run marcado como falho', function () {
    fakePosologyAi(new RuntimeException('OpenAI request failed [500]: segredo-interno'));

    $response = asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertStatus(422)
        ->assertJsonPath('message', __('manager_medicines.ai_failed_busy', ['provider' => AiProvider::OpenAI->label()]) . ' ' . __('manager_medicines.ai_try_again'));

    expect($response->getContent())->not->toContain('segredo-interno')
        ->and(AiRun::withoutGlobalScopes()->sole()->status)->toBe(AiRunStatus::Failed);
});

it('sem provedor configurado no servidor: botão some e o endpoint recusa', function () {
    config(['ai.provider_runtime' => 'real', 'services.openai.api_key' => null, 'services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $provider = fakePosologyAi(posologyJson());

    asPosologyAdmin()->get(route('manager.medicines.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('aiProviders', []));

    asPosologyAdmin()
        ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
        ->assertStatus(422)
        ->assertJsonPath('message', __('manager_medicines.ai_unavailable'));

    expect($provider->requests)->toBe([]);
});

it('[SEGURANÇA] medicamento de clínica não é alcançável (404) e nada é enviado à IA', function () {
    $clinic   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $own      = Medicine::withoutGlobalScopes()->create(['entity_id' => $clinic->id, 'name' => 'DA CLINICA', 'active' => true]);
    $provider = fakePosologyAi(posologyJson());

    asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $own->id])->assertNotFound();

    expect($provider->requests)->toBe([]);
});

it('[SEGURANÇA] papel SaaS não-admin não usa', function () {
    $support = User::factory()->create();
    createEntityUser($this->saas, $support, SaasRule::Support->value);
    fakePosologyAi(posologyJson());

    test()->actingAs($support)->withSession([
        'selected_entity_id'        => $this->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => SaasRule::Support->value,
    ])->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])->assertForbidden();

    expect(AiRun::withoutGlobalScopes()->count())->toBe(0);
});

it('sem id nem nome: 422 de validação', function () {
    fakePosologyAi(posologyJson());

    asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), [])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('limite de 10 sugestões por minuto (chamada paga)', function () {
    fakePosologyAi(posologyJson());

    foreach (range(1, 10) as $_) {
        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])->assertOk();
    }

    asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])->assertStatus(429);
});

describe('escolha da IA', function () {
    /** @return array<string, PosologyFakeProvider> fakes por provedor (cada um com a sua resposta) */
    function fakePosologyAiByProvider(array $replies): array
    {
        $providers = [];

        foreach (AiProvider::cases() as $case) {
            $providers[$case->value] = new PosologyFakeProvider($case, $replies[$case->value] ?? posologyJson());
        }

        app()->instance(AiProviderManager::class, new AiProviderManager($providers, app(AiProviderSettings::class)));

        return $providers;
    }

    it('uma IA configurada: gera direto com ela, em UMA chamada', function () {
        app(AiProviderSettings::class)->setEnabledCodes([AiProvider::Gemini->value]);
        $fakes = fakePosologyAiByProvider([]);

        asPosologyAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('aiProviders', 1)
                ->where('aiProviders.0.code', 'gemini')
                ->where('aiProviders.0.label', AiProvider::Gemini->label()));

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
            ->assertOk()
            ->assertJsonPath('suggestion.provider', 'gemini')
            ->assertJsonPath('suggestion.provider_label', AiProvider::Gemini->label());

        expect($fakes['gemini']->requests)->toHaveCount(1)
            ->and($fakes['openai']->requests)->toBe([])
            ->and(AiRun::withoutGlobalScopes()->sole()->mode->value)->toBe('economy');
    });

    it('várias IAs: sem escolha o servidor pede a escolha e não chama nenhuma', function () {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'anthropic', 'gemini']);
        $fakes = fakePosologyAiByProvider([]);

        asPosologyAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('aiProviders', 3));

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_medicines.ai_choose_provider'));

        expect(AiRun::withoutGlobalScopes()->count())->toBe(0)
            ->and(collect($fakes)->sum(fn ($f) => count($f->requests)))->toBe(0);
    });

    it('várias IAs: usa SÓ a escolhida (uma chamada, sem revisor), com o registro da execução', function () {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'anthropic', 'gemini']);
        $fakes = fakePosologyAiByProvider(['anthropic' => posologyJson(['dosage' => '2 gotas'])]);

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'anthropic'])
            ->assertOk()
            ->assertJsonPath('suggestion.dosage', '2 gotas')
            ->assertJsonPath('suggestion.provider_label', AiProvider::Anthropic->label());

        $run = AiRun::withoutGlobalScopes()->sole();
        expect($fakes['anthropic']->requests)->toHaveCount(1)
            ->and($fakes['openai']->requests)->toBe([])
            ->and($fakes['gemini']->requests)->toBe([])
            ->and($run->mode->value)->toBe('economy')
            ->and($run->input_summary['metadata']['pinned_provider'])->toBe('anthropic')
            ->and(DB::table('ai_run_provider_calls')->where('ai_run_id', $run->id)->pluck('provider')->all())
            ->toBe(['anthropic']);
    });

    it('IA escolhida falha: diz qual IA e o motivo (demora/sobrecarga), não troca por outra', function (string $error, string $cause) {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'gemini']);
        $fakes = fakePosologyAiByProvider(['gemini' => new RuntimeException($error)]);

        $response = asPosologyAdmin()
            ->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'gemini'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_medicines.' . $cause, ['provider' => AiProvider::Gemini->label()]) . ' ' . __('manager_medicines.ai_try_other'));

        expect($response->getContent())->not->toContain('segredo')
            ->and($fakes['openai']->requests)->toBe([]);

        // Painel de uso mostra o modelo que falhou (antes: "unknown").
        expect(DB::table('ai_run_provider_calls')->value('model'))
            ->toBe(app(AiProviderSettings::class)->model('gemini'));
    })->with([
        'tempo esgotado' => ['cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received segredo', 'ai_failed_timeout'],
        'sobrecarga'     => ['Gemini request failed [503]: This model is currently experiencing high demand segredo', 'ai_failed_busy'],
        'outro erro'     => ['Gemini request failed [400]: segredo', 'ai_failed_provider'],
    ]);

    it('IA "Configurada" no painel entra na escolha mesmo SEM papel no assistente (Principal primeiro)', function () {
        config(['ai.provider_runtime' => 'real', 'services.openai.api_key' => 'sk-test', 'services.gemini.api_key' => 'g-test', 'services.anthropic.api_key' => null]);
        // Assistente clínico só com o Principal (OpenAI): o Gemini fica sem papel.
        app(AiProviderSettings::class)->setRoleAssignments(['primary' => 'openai']);
        $fakes = fakePosologyAiByProvider(['gemini' => posologyJson(['dosage' => 'gemini dose'])]);

        asPosologyAdmin()->get(route('manager.medicines.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('aiProviders', fn ($providers) => collect($providers)->pluck('code')->all() === ['openai', 'gemini']));

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'gemini'])
            ->assertOk()
            ->assertJsonPath('suggestion.dosage', 'gemini dose');

        expect($fakes['gemini']->requests)->toHaveCount(1)
            ->and($fakes['openai']->requests)->toBe([])
            // O assistente clínico continua só com o Principal (nada mudou para as clínicas).
            ->and(app(AiProviderSettings::class)->enabledCodes())->toBe(['openai']);
    });

    it('lista mudou com a página aberta: servidor sinaliza para a tela recarregar as IAs', function () {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'gemini']);
        fakePosologyAiByProvider([]);

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id])
            ->assertStatus(422)->assertJsonPath('reason', 'stale_providers');
        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'anthropic'])
            ->assertStatus(422)->assertJsonPath('reason', 'stale_providers');
    });

    it('IA não habilitada ou desconhecida é recusada', function () {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'gemini']);
        $fakes = fakePosologyAiByProvider([]);

        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'anthropic'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('manager_medicines.ai_provider_invalid'));
        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'xpto'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');

        expect($fakes['anthropic']->requests)->toBe([]);
    });

    it('[SEGURANÇA] provedor fixado SEM chave nunca é chamado (nem trocado por outro)', function () {
        config(['ai.provider_runtime' => 'real', 'services.openai.api_key' => 'sk-test', 'services.anthropic.api_key' => null]);
        app(AiProviderSettings::class)->setEnabledCodes(['openai']);
        $fakes = fakePosologyAiByProvider([]);

        $run = AiRun::query()->create([
            'entity_id'         => $this->saas->id,
            'requested_by'      => $this->admin->id,
            'workflow'          => 'medicine_posology',
            'mode'              => 'economy',
            'risk_level'        => 'medium',
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 0,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            'input_summary'     => ['user_prompt' => 'x', 'metadata' => ['pinned_provider' => 'anthropic']],
        ]);

        try {
            app(AiRunExecutionService::class)->execute($run);
        } catch (Throwable) {
            // falha esperada: provedor fixado sem credencial
        }

        expect($fakes['anthropic']->requests)->toBe([])
            ->and($fakes['openai']->requests)->toBe([])
            ->and($run->fresh()->status)->toBe(AiRunStatus::Failed);
    });

    it('[LGPD] provedor bloqueado para pacientes só atende fluxo que declara não levar dado de paciente', function () {
        app(AiProviderSettings::class)->setEnabledCodes(['openai', 'gemini']);
        $fakes = fakePosologyAiByProvider([]);

        $run = AiRun::query()->create([
            'entity_id'         => $this->saas->id,
            'requested_by'      => $this->admin->id,
            'workflow'          => 'report_drafting',
            'mode'              => 'economy',
            'risk_level'        => 'medium',
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 0,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            // Sem "patient_data" => false: o padrão é tratar como dado de paciente.
            'input_summary' => ['user_prompt' => 'x', 'metadata' => ['pinned_provider' => 'gemini']],
        ]);

        try {
            app(AiRunExecutionService::class)->execute($run);
        } catch (Throwable) {
            // falha esperada: Gemini API bloqueada para pacientes (termos)
        }

        expect($fakes['gemini']->requests)->toBe([])
            ->and($fakes['openai']->requests)->toBe([])
            ->and($run->fresh()->status)->toBe(AiRunStatus::Failed);

        // A posologia (só catálogo) declara patient_data=false e segue podendo usar o Gemini.
        asPosologyAdmin()->postJson(route('manager.medicines.ai-posology'), ['medicine_id' => $this->cmed->id, 'provider' => 'gemini'])
            ->assertOk();

        expect($fakes['gemini']->requests)->toHaveCount(1)
            ->and(AiRun::withoutGlobalScopes()->where('workflow', 'medicine_posology')->sole()->input_summary['metadata']['patient_data'])->toBeFalse();
    });
});
