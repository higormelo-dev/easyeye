<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiPayloadEnricher, AiProviderSettings};
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\Billing\PlatformExpenseCategory;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\Billing\PlatformExpense;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlatformFinanceService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Manager: P&L interno do EasyEye (receita/despesa/lucro do próprio SaaS) +
 * análise por IA (digest estruturado e "converse com os dados").
 *
 * Autorização: EntityGate::SaasOwnerFinancial em TODA ação — mais restrito
 * que SaasFinancial (usado no restante do painel financeiro do manager,
 * ex.: AiCreditPurchasesController): só Admin do SaaS ou dono (is_owner),
 * conforme pedido explícito do produto ("exclusiva dos donos/administradores
 * gerais"). Ver app/Providers/AuthServiceProvider.php.
 *
 * Os runs de IA aqui NÃO passam pela carteira de créditos (reserve/consume) —
 * não é um recurso vendável/cobrado de clínica, é ferramenta interna. Criados
 * com estimated/reserved/consumed_credits=0, o que faz AiRunExecutionService
 * pular o wallet inteiro (reserved=0 → consumed=min(actual,0)=0 → nenhuma
 * chamada de wallet acontece — ver AiRunExecutionService::execute()). O custo
 * REAL em USD ainda é gravado em ai_run_provider_calls.raw_cost_usd — e por
 * isso entra automaticamente na categoria "IA" do próprio P&L do próximo período.
 */
class FinanceController extends Controller
{
    private const PERIOD_PRESETS = ['this_month', '3m', '6m', '12m', 'custom'];

    public function __construct(
        private readonly PlatformFinanceService $financeService,
        private readonly AiPayloadEnricher $enricher,
        private readonly AiProviderSettings $providerSettings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeSaasEntity();

        $period = $this->resolvePeriod($request);

        return Inertia::render('Panel/Manager/Finance/Index', [
            'summary' => $this->financeService->summary($period['from'], $period['to']),
            'period'  => [
                'preset' => $period['preset'],
                'from'   => $period['from']->toDateString(),
                'to'     => $period['to']->toDateString(),
            ],
            'expenseCategories' => PlatformExpenseCategory::options(),
            'expenses'          => PlatformExpense::query()
                ->whereBetween('effective_at', [$period['from']->toDateString(), $period['to']->toDateString()])
                ->orderByDesc('effective_at')
                ->get()
                ->map(fn (PlatformExpense $e) => $this->serializeExpense($e))
                ->values(),
            'ai' => [
                'mode' => $this->defaultMode(),
                'urls' => [
                    'digest' => route('manager.finance.digest'),
                    'chat'   => route('manager.finance.chat'),
                    'show'   => route('manager.finance.ai-runs.show', ['aiRun' => '__ID__']),
                ],
            ],
            't' => trans('manager_finance'),
        ]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity();

        $data = $this->validateExpense($request);

        $expense = PlatformExpense::create($data);

        $this->audit->recordAdminAction(
            event: 'platform_expense.created',
            targetEntityId: null,
            targetUserId: null,
            auditableType: PlatformExpense::class,
            auditableId: (string) $expense->id,
            reason: 'Lançamento de despesa operacional do EasyEye',
            newValues: $data,
            request: $request,
        );

        return response()->json(['message' => 'Despesa registrada.', 'expense' => $this->serializeExpense($expense)], 201);
    }

    public function updateExpense(Request $request, PlatformExpense $expense): JsonResponse
    {
        $this->authorizeSaasEntity();

        $old  = $expense->only(['category', 'description', 'amount', 'effective_at', 'recurring', 'notes']);
        $data = $this->validateExpense($request);

        $expense->update($data);

        $this->audit->recordAdminAction(
            event: 'platform_expense.updated',
            targetEntityId: null,
            targetUserId: null,
            auditableType: PlatformExpense::class,
            auditableId: (string) $expense->id,
            reason: 'Edição de despesa operacional do EasyEye',
            newValues: $data,
            request: $request,
            oldValues: $old,
        );

        return response()->json(['message' => 'Despesa atualizada.', 'expense' => $this->serializeExpense($expense->fresh())]);
    }

    public function destroyExpense(Request $request, PlatformExpense $expense): JsonResponse
    {
        $this->authorizeSaasEntity();

        $old = $expense->only(['category', 'description', 'amount', 'effective_at']);
        $expense->delete();

        $this->audit->recordAdminAction(
            event: 'platform_expense.deleted',
            targetEntityId: null,
            targetUserId: null,
            auditableType: PlatformExpense::class,
            auditableId: (string) $expense->id,
            reason: 'Exclusão de despesa operacional do EasyEye',
            newValues: [],
            request: $request,
            oldValues: $old,
        );

        return response()->json(['message' => 'Despesa removida.']);
    }

    /**
     * Digest estruturado (ganhando/perdendo/oportunidades/ações) do período
     * selecionado. Cria e dispara o AiRun; o front faz polling em showAiRun.
     */
    public function digest(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity();

        $period = $this->resolvePeriod($request);
        $run    = $this->createPlatformRun(
            workflow: 'platform_finance_digest',
            userPrompt: 'Gerar o digest financeiro do período selecionado (ganhando, perdendo, oportunidades, ações sugeridas).',
            period: $period,
        );

        return response()->json(['run_id' => $run->id, 'status' => $run->status?->value], 201);
    }

    /**
     * "Converse com os dados" — chat livre multi-turno sobre o P&L do
     * período selecionado. Mesmo padrão de conversation_id do assistant_chat
     * clínico (AiFloatingAssistant.vue), workflow disjunto.
     */
    public function chat(Request $request): JsonResponse
    {
        $this->authorizeSaasEntity();

        $validated = $request->validate([
            'user_prompt'     => ['required', 'string', 'min:4', 'max:4000'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        $period = $this->resolvePeriod($request);
        $run    = $this->createPlatformRun(
            workflow: 'platform_finance_chat',
            userPrompt: $validated['user_prompt'],
            period: $period,
            conversationId: $validated['conversation_id'] ?? null,
        );

        return response()->json(['run_id' => $run->id, 'status' => $run->status?->value], 201);
    }

    /**
     * Polling do run (digest ou chat). Sem ceremonial de "aprovar" — não é
     * documento clínico nem gasta crédito de ninguém; o próprio Gate desta
     * rota já é a autorização. Ao detectar WaitingApproval, finaliza direto
     * pra Approved (nunca reusa AiRunsController::approve(), que é
     * doctor-only e específico de prontuário — ver AiPayloadEnricher).
     */
    public function showAiRun(AiRun $aiRun): JsonResponse
    {
        $this->authorizeSaasEntity();

        abort_unless(str_starts_with((string) $aiRun->workflow, 'platform_finance_'), 404);
        abort_unless((string) $aiRun->entity_id === (string) $this->currentSaasEntity()->id, 404);

        if ($aiRun->status === AiRunStatus::WaitingApproval) {
            $aiRun->update([
                'status'      => AiRunStatus::Approved->value,
                'approved_by' => (string) auth()->id(),
                'approved_at' => now(),
            ]);
            $aiRun->refresh();
        }

        return response()->json([
            'id'               => $aiRun->id,
            'status'           => $aiRun->status?->value,
            'current_role'     => $aiRun->current_role,
            'current_provider' => $aiRun->current_provider,
            'final_output'     => $aiRun->final_output,
            'error_message'    => $aiRun->error_message,
            'workflow'         => $aiRun->workflow,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function authorizeSaasEntity(): void
    {
        Gate::authorize(EntityGate::SaasOwnerFinancial->value, $this->currentSaasEntity());
    }

    private function currentSaasEntity(): Entity
    {
        return Entity::findOrFail(session('selected_entity_id'));
    }

    /**
     * @return array{from: Carbon, to: Carbon, preset: string}
     */
    private function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'preset' => ['nullable', 'string', Rule::in(self::PERIOD_PRESETS)],
            'from'   => ['nullable', 'date_format:Y-m-d', 'required_if:preset,custom'],
            'to'     => ['nullable', 'date_format:Y-m-d', 'required_if:preset,custom', 'after_or_equal:from'],
        ]);

        $preset = $validated['preset'] ?? 'this_month';
        $now    = Carbon::now();

        if ($preset === 'custom') {
            return [
                'from'   => Carbon::parse($validated['from'])->startOfDay(),
                'to'     => Carbon::parse($validated['to'])->endOfDay(),
                'preset' => 'custom',
            ];
        }

        $from = match ($preset) {
            '3m'    => $now->copy()->subMonths(3)->startOfDay(),
            '6m'    => $now->copy()->subMonths(6)->startOfDay(),
            '12m'   => $now->copy()->subMonths(12)->startOfDay(),
            default => $now->copy()->startOfMonth(), // this_month
        };

        return ['from' => $from, 'to' => $now->copy()->endOfDay(), 'preset' => $preset];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'category'     => ['required', Rule::enum(PlatformExpenseCategory::class)],
            'description'  => ['required', 'string', 'max:255'],
            'amount'       => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'effective_at' => ['required', 'date_format:Y-m-d'],
            'recurring'    => ['nullable', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExpense(PlatformExpense $expense): array
    {
        return [
            'id'             => $expense->id,
            'category'       => $expense->category?->value,
            'category_label' => $expense->category?->label(),
            'description'    => $expense->description,
            'amount'         => (float) $expense->amount,
            'effective_at'   => $expense->effective_at?->toDateString(),
            'recurring'      => (bool) $expense->recurring,
            'notes'          => $expense->notes,
        ];
    }

    /**
     * @param array{from: Carbon, to: Carbon, preset: string} $period
     */
    private function createPlatformRun(
        string $workflow,
        string $userPrompt,
        array $period,
        ?string $conversationId = null,
    ): AiRun {
        $entity   = $this->currentSaasEntity();
        $entityId = (string) $entity->id;

        $summary = $this->financeService->summary($period['from'], $period['to']);

        $payload = [
            'workflow'    => $workflow,
            'mode'        => $this->defaultMode(),
            'risk_level'  => AiRiskLevel::Medium->value,
            'user_prompt' => $userPrompt,
            'context'     => [
                'periodo' => [
                    'de'     => $period['from']->toDateString(),
                    'ate'    => $period['to']->toDateString(),
                    'preset' => $period['preset'],
                ],
                'resumo_financeiro' => $summary,
            ],
        ];

        if ($conversationId !== null) {
            $payload['conversation_id'] = $conversationId;
        }

        $enriched = $this->enricher->enrich($payload, $entityId, canConsensus: true);

        $run = AiRun::query()->create([
            'entity_id'       => $entityId,
            'requested_by'    => (string) auth()->id(),
            'workflow'        => $enriched['workflow'],
            'mode'            => $enriched['mode'],
            'risk_level'      => $enriched['risk_level'],
            'conversation_id' => $enriched['conversation_id'] ?? null,
            // Pending (não Reserved): não passa pela carteira de créditos —
            // "Reserved" implicaria uma reserva real no ledger, que não
            // existe aqui (0 crédito, sem transação). Ver docblock da classe.
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 0,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            'input_summary'     => [
                'user_prompt'   => $enriched['user_prompt'],
                'system_prompt' => $enriched['system_prompt'] ?? null,
                'context'       => $enriched['context'] ?? [],
                'expects_json'  => (bool) ($enriched['expects_json'] ?? false),
                'metadata'      => [
                    'source'     => 'manager_finance_panel',
                    'guardrails' => $enriched['_guardrails'] ?? [],
                ],
            ],
        ]);

        RunAiWorkflowJob::dispatch((string) $run->id)->afterCommit();

        return $run;
    }

    /**
     * Modo padrão: o mais robusto disponível (Validated se houver 2+
     * provedores habilitados) — análise financeira se beneficia de
     * cross-check entre provedores; sem FeatureKey de plano envolvido,
     * então usa o teto que a config de provedores do sistema permitir.
     */
    private function defaultMode(): string
    {
        $modes = $this->providerSettings->availableModes();

        return ($modes[0] ?? AiRunMode::Economy)->value;
    }
}
