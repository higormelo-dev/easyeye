<?php

declare(strict_types=1);

use App\Domains\AI\Contracts\{AiCircuitBreakerInterface, AiProviderInterface};
use App\Domains\AI\Exceptions\AiProviderRequestException;
use App\Domains\AI\Models\{AiCircuitBreaker, AiRun};
use App\Domains\AI\Services\{AiOrchestrator, AiPricingService, AiProviderManager, AiProviderSettings};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData};
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\SaasRule;
use App\Models\{Entity, User};
use App\Notifications\AiOperationalAlertNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Fakes\InMemoryCallStore;

/**
 * Falta de crédito / chave recusada: o circuito de TODAS as clínicas só abre
 * com sinal estruturado do provedor (código do erro, 401). Inferido do texto
 * da mensagem — que pode ecoar o pedido de uma clínica — conta só no limiar
 * da clínica. O time é avisado nos dois casos.
 */
function runOrchestratorAgainst(AiProviderRequestException $error, string $entityId): void
{
    app(AiProviderSettings::class)->setEnabledCodes([AiProvider::OpenAI->value]);

    $failing = new class($error) implements AiProviderInterface {
        public function __construct(private AiProviderRequestException $error)
        {
        }

        public function generate(AiRequestData $request): AiProviderResponseData
        {
            throw $this->error;
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
            return AiProvider::OpenAI;
        }
    };

    $orchestrator = new AiOrchestrator(
        new AiProviderManager([AiProvider::OpenAI->value => $failing], app(AiProviderSettings::class)),
        new InMemoryCallStore(),
        app(AiPricingService::class),
        app(AiCircuitBreakerInterface::class),
    );

    $run     = new AiRun(['entity_id' => $entityId, 'workflow' => 'report_drafting', 'mode' => AiRunMode::Economy->value, 'risk_level' => AiRiskLevel::Medium->value, 'status' => AiRunStatus::Reserved->value]);
    $run->id = (string) Str::uuid();

    try {
        $orchestrator->execute($run, new AiRequestData(
            workflow: 'report_drafting',
            mode: AiRunMode::Economy,
            userPrompt: 'Rascunho.',
            systemPrompt: 'Apoio.',
            riskLevel: AiRiskLevel::Medium,
        ));
    } catch (AiProviderRequestException) {
        // esperado: a única IA falhou
    }
}

beforeEach(function () {
    Notification::fake();

    $saas        = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($saas, $this->admin, SaasRule::Admin->value);

    $this->clinic = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);
});

it('sem crédito pelo CÓDIGO do erro: suspende o provedor para todas as clínicas e avisa o time', function () {
    runOrchestratorAgainst(
        new AiProviderRequestException('OpenAI request failed [429/insufficient_quota]: x', AiProvider::OpenAI, 429, 'insufficient_quota', AiProviderRequestException::CATEGORY_QUOTA),
        (string) $this->clinic->id,
    );

    expect(app(AiCircuitBreakerInterface::class)->isOpen(AiProvider::OpenAI, (string) $this->other->id))->toBeTrue();
    Notification::assertSentTo($this->admin, AiOperationalAlertNotification::class, fn (AiOperationalAlertNotification $n) => $n->type === 'quota');
});

it('sem crédito só pelo TEXTO da mensagem: não suspende as outras clínicas — conta no limiar da clínica, e avisa o time', function () {
    runOrchestratorAgainst(
        new AiProviderRequestException('OpenAI request failed [400/invalid_request_error]: Your credit balance is too low', AiProvider::OpenAI, 400, 'invalid_request_error', AiProviderRequestException::CATEGORY_QUOTA, null, accountWide: false),
        (string) $this->clinic->id,
    );

    $breaker = app(AiCircuitBreakerInterface::class);
    $row     = AiCircuitBreaker::query()->where('provider_code', 'openai')->where('entity_id', $this->clinic->id)->first();

    expect($breaker->isOpen(AiProvider::OpenAI, (string) $this->other->id))->toBeFalse()
        ->and($breaker->isOpen(AiProvider::OpenAI))->toBeFalse()
        ->and($row?->failure_count)->toBe(1)
        ->and($row?->last_trigger_type)->toBe('quota')
        ->and($row?->isOpen())->toBeFalse();
    Notification::assertSentTo($this->admin, AiOperationalAlertNotification::class);
});
