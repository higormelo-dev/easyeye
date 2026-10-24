<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Services\{AiCreditPurchaseService, AiExchangeRateService, AiPricingPolicyResolver, AiPricingService, AiPricingSettings};
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Gate, Log};
use Inertia\{Inertia, Response};
use InvalidArgumentException;
use RuntimeException;

/**
 * Manager → IA → Precificação: câmbio manual (histórico append-only, com autor e data), markup, pisos e limites do pacote
 * personalizado. SÓ o dono/admin financeiro do SaaS (Gate SaasOwnerFinancial, o mesmo de Uso de IA): câmbio e markup são
 * segredo comercial e NUNCA aparecem em rota de clínica (AiPricingLeakageTest). Toda mudança exige motivo e fica na
 * auditoria (`manager.ai_pricing.*`). Plano: docs/internal/planos/ai-credit-packs-brl-plano.md §4.
 */
class AiPricingController extends Controller
{
    /** Custos de provedor (US$) usados nos exemplos da conta. */
    private const EXAMPLE_COSTS_USD = [0.001, 0.01, 0.05, 0.25, 1.0];

    public function __construct(
        private readonly AiExchangeRateService $rates,
        private readonly AiPricingSettings $settings,
        private readonly AiPricingPolicyResolver $policies,
        private readonly AiPricingService $pricing,
        private readonly AiCreditPurchaseService $purchases,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): Response
    {
        $this->authorizeOwner();

        $policy  = $this->policies->current();
        $history = $this->rates->history();
        $current = $this->rates->current();

        $examples = array_map(function (float $usd) use ($policy): array {
            $cents = $this->pricing->chargeCentsFromUsd($usd, 'exam_assistant', $policy);

            return [
                'raw_cost_usd'     => $usd,
                'charge_cents'     => $cents,
                'charge_formatted' => Money::formatBrl($cents),
                'minimum_applied'  => $cents === $policy->minimumChargeCentsFor('exam_assistant'),
            ];
        }, self::EXAMPLE_COSTS_USD);

        return Inertia::render('Panel/Manager/AiPricing/Index', [
            'rate' => [
                'rate'             => $current['rate'],
                'source'           => $current['source'],
                'effective_at'     => $current['effective_at']?->toIso8601String(),
                'recorded_by_name' => $current['recorded_by_name'],
                'note'             => $current['note'],
                'is_fallback'      => $current['is_fallback'],
            ],
            'settings' => $this->settingsPayload(),
            'packages' => $this->purchases->packages(),
            'examples' => $examples,
            'history'  => $history->through(fn ($row): array => [
                'id'               => (string) $row->id,
                'source'           => $row->source->value,
                'rate_brl_per_usd' => (float) $row->rate_brl_per_usd,
                'effective_at'     => $row->effective_at?->toIso8601String(),
                'recorded_by_name' => $row->recordedBy?->name,
                'note'             => $row->note,
            ]),
            't'    => trans('manager_ai_pricing'),
            'urls' => [
                'settings'       => route('manager.ai-pricing.settings'),
                'exchange_rates' => route('manager.ai-pricing.exchange-rates.store'),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeOwner();

        $validated = $request->validate([
            'markup_multiplier'                => ['sometimes', 'numeric'],
            'minimum_charge_cents_default'     => ['sometimes', 'integer'],
            'minimum_charge_cents_by_workflow' => ['sometimes', 'array'],
            'custom_min_cents'                 => ['sometimes', 'integer'],
            'custom_max_cents'                 => ['sometimes', 'integer'],
            'reason'                           => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $before = $this->settingsPayload();
        $data   = array_diff_key($validated, ['reason' => true]);

        try {
            $this->settings->assertValid($data);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => __("manager_ai_pricing.errors.{$e->getMessage()}"),
                'field'   => $e->getMessage(),
            ], 422);
        }

        // Preço e trilha de auditoria na MESMA transação: sem a linha em audit_logs, nada entra em vigor.
        try {
            $after = $this->transactionalSettingsUpdate($data, $validated, $request, $before);
        } catch (RuntimeException $e) {
            $this->settings->forgetCache(); // cache não pode ficar com o valor desfeito pela transação

            throw $e;
        }

        Log::info('manager.ai_pricing.settings_updated', ['by' => (string) auth()->id(), 'fields' => array_keys($data)]);

        return response()->json(['message' => __('manager_ai_pricing.settings_saved'), 'settings' => $after]);
    }

    public function storeExchangeRate(Request $request): JsonResponse
    {
        $this->authorizeOwner();

        $validated = $request->validate([
            'rate_brl_per_usd' => ['required', 'numeric'],
            'effective_at'     => ['nullable', 'date'],
            'note'             => ['nullable', 'string', 'max:255'],
            'reason'           => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $effectiveAt = isset($validated['effective_at']) ? Carbon::parse($validated['effective_at']) : now();

        try {
            // Cotação e trilha de auditoria na MESMA transação: sem a linha em audit_logs, a cotação não entra em vigor.
            $row = DB::transaction(function () use ($validated, $effectiveAt, $request) {
                $row = $this->rates->record(
                    (float) $validated['rate_brl_per_usd'],
                    $effectiveAt,
                    (string) auth()->id(),
                    $validated['note'] ?? null,
                );

                $this->auditedOrFail('manager.ai_pricing.exchange_rate_recorded', fn () => $this->audit->recordAdminAction(
                    event: 'manager.ai_pricing.exchange_rate_recorded',
                    targetEntityId: (string) session('selected_entity_id'),
                    targetUserId: null,
                    auditableType: 'ai_exchange_rate',
                    auditableId: (string) $row->id,
                    reason: (string) $validated['reason'],
                    newValues: ['rate_brl_per_usd' => (float) $row->rate_brl_per_usd, 'effective_at' => $row->effective_at?->toIso8601String(), 'note' => $row->note],
                    request: $request,
                ));

                return $row;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => __("manager_ai_pricing.errors.{$e->getMessage()}"),
                'field'   => 'rate_brl_per_usd',
            ], 422);
        }

        Log::info('manager.ai_pricing.exchange_rate_recorded', ['by' => (string) auth()->id(), 'id' => (string) $row->id]);

        return response()->json([
            'message'       => __('manager_ai_pricing.exchange_rate_saved'),
            'rate'          => $this->rates->current(),
            'history_first' => [
                'id'               => (string) $row->id,
                'source'           => $row->source->value,
                'rate_brl_per_usd' => (float) $row->rate_brl_per_usd,
                'effective_at'     => $row->effective_at?->toIso8601String(),
                'recorded_by_name' => auth()->user()?->name,
                'note'             => $row->note,
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            'markup_multiplier'                => $this->settings->markupMultiplier(),
            'minimum_charge_cents_default'     => $this->settings->minimumChargeCentsDefault(),
            'minimum_charge_cents_by_workflow' => $this->settings->minimumChargeCentsByWorkflow(),
            'custom_min_cents'                 => $this->settings->customMinCents(),
            'custom_max_cents'                 => $this->settings->customMaxCents(),
            'custom_step_cents'                => $this->settings->customStepCents(),
            'limits'                           => [
                'markup_min'            => AiPricingSettings::MARKUP_MIN,
                'markup_max'            => AiPricingSettings::MARKUP_MAX,
                'minimum_min'           => AiPricingSettings::MINIMUM_CHARGE_MIN,
                'minimum_max'           => AiPricingSettings::MINIMUM_CHARGE_MAX,
                'custom_hard_max_cents' => AiPricingSettings::CUSTOM_HARD_MAX_CENTS,
            ],
        ];
    }

    /**
     * AuditLogger engole falhas de escrita (não derruba requests comuns). Aqui a trilha é obrigatória: confere que a
     * linha do evento foi gravada nesta transação; senão lança e a transação desfaz a mudança de preço.
     */
    /** @param array<string, mixed> $data @param array<string, mixed> $validated @param array<string, mixed> $before @return array<string, mixed> */
    /**
     * Preço e auditoria na mesma transação. NENHUMA leitura por cache acontece dentro dela: o "depois" é projetado a
     * partir dos dados validados, e o cache só é invalidado/relido após o commit — um valor ainda não confirmado nunca é
     * publicado no Redis compartilhado (achado Sentinela S3).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $before
     *
     * @return array<string, mixed>
     */
    private function transactionalSettingsUpdate(array $data, array $validated, Request $request, array $before): array
    {
        $after = $this->projectSettings($before, $data);

        DB::transaction(function () use ($data, $validated, $request, $before, $after): void {
            $this->settings->update($data);

            $this->auditedOrFail('manager.ai_pricing.settings_updated', fn () => $this->audit->recordAdminAction(
                event: 'manager.ai_pricing.settings_updated',
                targetEntityId: (string) session('selected_entity_id'),
                targetUserId: null,
                auditableType: 'ai_pricing',
                auditableId: (string) session('selected_entity_id'),
                reason: (string) $validated['reason'],
                newValues: $after,
                request: $request,
                oldValues: $before,
            ));
        });

        // Só depois do commit: cache limpo e relido com o valor confirmado.
        $this->settings->forgetCache();

        return $this->settingsPayload();
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function projectSettings(array $before, array $data): array
    {
        $after = $before;

        if (array_key_exists('markup_multiplier', $data)) {
            $after['markup_multiplier'] = (float) number_format((float) $data['markup_multiplier'], 3, '.', ''); // mesma precisão da persistência
        }

        foreach (['minimum_charge_cents_default', 'custom_min_cents', 'custom_max_cents'] as $field) {
            if (array_key_exists($field, $data)) {
                $after[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('minimum_charge_cents_by_workflow', $data)) {
            // Persistência substitui o mapa de overrides inteiro: efetivo = padrões do config + request (não o mapa antigo).
            $after['minimum_charge_cents_by_workflow'] = array_map('intval', [...(array) config('ai.pricing.minimum_charge_cents_by_workflow', []), ...(array) $data['minimum_charge_cents_by_workflow']]);
        }

        return $after;
    }

    private function auditedOrFail(string $event, callable $record): void
    {
        $before = DB::table('audit_logs')->where('event', $event)->count();
        $record();

        if (DB::table('audit_logs')->where('event', $event)->count() <= $before) {
            throw new RuntimeException("Auditoria obrigatória não gravada ({$event}); mudança de preço desfeita.");
        }
    }

    private function authorizeOwner(): void
    {
        Gate::authorize(EntityGate::SaasOwnerFinancial->value, Entity::query()->findOrFail(session('selected_entity_id')));
    }
}
