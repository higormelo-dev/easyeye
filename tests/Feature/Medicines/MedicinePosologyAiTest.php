<?php

use App\Domains\AI\Contracts\AiProviderInterface;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunStatus};
use App\Enums\{MedicineSource, SaasRule};
use App\Models\{Entity, Medicine, User};
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
        ->assertJsonPath('message', __('manager_medicines.ai_failed'));

    expect($response->getContent())->not->toContain('segredo-interno')
        ->and(AiRun::withoutGlobalScopes()->sole()->status)->toBe(AiRunStatus::Failed);
});

it('sem provedor configurado no servidor: botão some e o endpoint recusa', function () {
    config(['ai.provider_runtime' => 'real', 'services.openai.api_key' => null, 'services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $provider = fakePosologyAi(posologyJson());

    asPosologyAdmin()->get(route('manager.medicines.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('aiAvailable', false));

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
